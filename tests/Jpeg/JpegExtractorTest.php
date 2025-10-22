<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Jpeg;

require_once __DIR__ . '/../../src/Core/Exceptions.php';

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JpegExtractorTest extends TestCase
{
    private const EXIF_SIGNATURE = "Exif\0\0";
    private const XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";
    private const ICC_SIGNATURE = "ICC_PROFILE\0";
    private const IPTC_SIGNATURE = "Photoshop 3.0\0";

    /**
     * @param list<string> $segments
     * @param list<string> $expectedExif
     * @param list<string> $expectedXmp
     */
    #[DataProvider('provideApp1Variants')]
    public function testExtractsExifAndXmpInAnyOrder(array $segments, array $expectedExif, array $expectedXmp): void
    {
        $jpeg = self::jpeg(...$segments);
        $extractor = $this->createExtractor($jpeg);

        self::assertSame($expectedExif, $extractor->extractExifBlobs());
        self::assertSame($expectedXmp, $extractor->extractXmpPackets());
    }

    /** @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}> */
    public static function provideApp1Variants(): iterable
    {
        $exifPayload = 'primary-exif';
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">One</x:xmpmeta>';

        yield 'only-exif' => [
            [self::segment(0xE1, self::EXIF_SIGNATURE . $exifPayload)],
            [$exifPayload],
            [],
        ];

        yield 'only-xmp' => [
            [self::segment(0xE1, self::XMP_SIGNATURE . $xmpXml)],
            [],
            [$xmpXml],
        ];

        yield 'xmp-before-exif' => [
            [
                self::segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
                self::segment(0xE1, self::EXIF_SIGNATURE . $exifPayload),
            ],
            [$exifPayload],
            [$xmpXml],
        ];

        yield 'exif-before-xmp' => [
            [
                self::segment(0xE1, self::EXIF_SIGNATURE . $exifPayload),
                self::segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
            ],
            [$exifPayload],
            [$xmpXml],
        ];
    }

    public function testLargeExifOver64KBIsHandled(): void
    {
        $firstBlob = str_repeat('A', 40_000);
        $secondBlob = str_repeat('B', 30_000);
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Large</x:xmpmeta>';

        $jpeg = self::jpeg(
            self::segment(0xE1, self::EXIF_SIGNATURE . $firstBlob),
            self::segment(0xE1, self::EXIF_SIGNATURE . $secondBlob),
            self::segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$firstBlob, $secondBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    public function testIccProfileSegmentsAreMerged(): void
    {
        $iccPart1 = 'icc-part-one';
        $iccPart2 = 'icc-part-two';
        $segment1Payload = self::ICC_SIGNATURE . "\x01\x02" . $iccPart1;
        $segment2Payload = self::ICC_SIGNATURE . "\x02\x02" . $iccPart2;

        $jpeg = self::jpeg(
            self::segment(0xE2, $segment1Payload),
            self::segment(0xE2, $segment2Payload),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$segment1Payload, $segment2Payload], $extractor->getIccSegments());
        self::assertSame($iccPart1 . $iccPart2, $extractor->getIccProfile());
    }

    public function testIptcIsCollectedRaw(): void
    {
        $iptcOne = self::IPTC_SIGNATURE . 'payload-one';
        $iptcTwo = self::IPTC_SIGNATURE . 'payload-two';

        $jpeg = self::jpeg(
            self::segment(0xED, $iptcOne),
            self::segment(0xED, $iptcTwo),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$iptcOne, $iptcTwo], $extractor->getIptcPayloads());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function provideInvalidSegments(): iterable
    {
        $lengthTooSmall = "\xFF\xD8" . "\xFF\xE1\x00\x01" . "\xFF\xD9";
        $truncatedPayload = "\xFF\xD8" . "\xFF\xE1" . pack('n', 10) . 'abcde' . "\xFF\xD9";

        yield 'length-smaller-than-two' => [$lengthTooSmall, '/length/i'];
        yield 'truncated-payload' => [$truncatedPayload, '/truncated/i'];
    }

    #[DataProvider('provideInvalidSegments')]
    public function testInvalidLengthsAndUnexpectedEoiThrowParseError(string $jpeg, string $messagePattern): void
    {
        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($messagePattern);

        $extractor->extractExifBlobs();
    }

    public function testStopsAtSosIgnoresRestartMarkers(): void
    {
        $primaryExif = 'primary-before-sos';
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">BeforeSOS</x:xmpmeta>';
        $ignoredExif = 'ignored-after-sos';

        $jpeg = "\xFF\xD8"
            . self::segment(0xE1, self::EXIF_SIGNATURE . $primaryExif)
            . self::segment(0xE1, self::XMP_SIGNATURE . $xmpXml)
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'A'
            . "\xFF\xD0"
            . "\xFF\xE1" . pack('n', strlen(self::EXIF_SIGNATURE . $ignoredExif) + 2) . self::EXIF_SIGNATURE . $ignoredExif
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$primaryExif], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    private static function jpeg(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private function createExtractor(string $jpeg): JpegExtractor
    {
        $fh = fopen('php://temp', 'wb+');
        fwrite($fh, $jpeg);
        rewind($fh);

        return new JpegExtractor(new Stream($fh, strlen($jpeg)));
    }
}
