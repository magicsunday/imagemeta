<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the JPEG extractor using synthetic marker segments.
 *
 * @covers \MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor
 */
final class JpegExtractorTest extends TestCase
{
    private const EXIF_SIGNATURE = "Exif\0\0";
    private const XMP_SIGNATURE  = "http://ns.adobe.com/xap/1.0/\0";
    private const ICC_SIGNATURE  = "ICC_PROFILE\0";
    private const IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const int MARKER_APP1  = 0xE1;
    private const int MARKER_APP2  = 0xE2;
    private const int MARKER_APP13 = 0xED;

    /**
     * Verifies APP1 segments yield EXIF and XMP payloads regardless of ordering.
     *
     * @param list<string> $segments
     * @param list<string> $expectedExif
     * @param list<string> $expectedXmp
     */
    #[Test]
    #[DataProvider('provideApp1Variants')]
    public function extractsExifAndXmpInAnyOrder(array $segments, array $expectedExif, array $expectedXmp): void
    {
        $jpeg      = self::jpeg(...$segments);
        $extractor = $this->createExtractor($jpeg);

        self::assertSame($expectedExif, $extractor->extractExifBlobs());
        self::assertSame($expectedXmp, $extractor->extractXmpPackets());
    }

    /**
     * Provides APP1 segment permutations mixing EXIF and XMP payloads.
     *
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function provideApp1Variants(): iterable
    {
        $exifPayload = 'primary-exif';
        $xmpXml      = '<x:xmpmeta xmlns:x="adobe:ns:meta/">One</x:xmpmeta>';

        yield 'only-exif' => [
            [self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)],
            [$exifPayload],
            [],
        ];

        yield 'only-xmp' => [
            [self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml)],
            [],
            [$xmpXml],
        ];

        yield 'xmp-before-exif' => [
            [
                self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
                self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            ],
            [$exifPayload],
            [$xmpXml],
        ];

        yield 'exif-before-xmp' => [
            [
                self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
                self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
            ],
            [$exifPayload],
            [$xmpXml],
        ];
    }

    /**
     * Ensures multiple EXIF segments larger than 64KB are collected as-is.
     */
    #[Test]
    public function testLargeExifOver64KBIsHandled(): void
    {
        $firstBlob  = str_repeat('A', 40_000);
        $secondBlob = str_repeat('B', 30_000);
        $xmpXml     = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Large</x:xmpmeta>';

        $jpeg = self::jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $firstBlob),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $secondBlob),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$firstBlob, $secondBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Confirms ICC profile fragments are reordered and merged into a single profile.
     */
    #[Test]
    public function testIccProfileSegmentsAreMerged(): void
    {
        $iccPart1        = 'icc-part-one';
        $iccPart2        = 'icc-part-two';
        $segment1Payload = self::ICC_SIGNATURE . "\x01\x02" . $iccPart1;
        $segment2Payload = self::ICC_SIGNATURE . "\x02\x02" . $iccPart2;

        $jpeg = self::jpeg(
            self::segment(self::MARKER_APP2, $segment1Payload),
            self::segment(self::MARKER_APP2, $segment2Payload),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$segment1Payload, $segment2Payload], $extractor->getIccSegments());
        self::assertSame($iccPart1 . $iccPart2, $extractor->getIccProfile());
    }

    /**
     * Ensures APP13 segments with the Photoshop signature are stored verbatim.
     */
    #[Test]
    public function testIptcIsCollectedRaw(): void
    {
        $iptcOne = self::IPTC_SIGNATURE . 'payload-one';
        $iptcTwo = self::IPTC_SIGNATURE . 'payload-two';

        $jpeg = self::jpeg(
            self::segment(self::MARKER_APP13, $iptcOne),
            self::segment(self::MARKER_APP13, $iptcTwo),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$iptcOne, $iptcTwo], $extractor->getIptcPayloads());
    }

    /**
     * Provides malformed JPEG structures expected to raise parse errors.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideInvalidSegments(): iterable
    {
        $lengthTooSmall   = "\xFF\xD8\xFF\xE1\x00\x01\xFF\xD9";
        $truncatedPayload = "\xFF\xD8\xFF\xE1" . pack('n', 10) . 'abcde' . "\xFF\xD9";

        yield 'length-smaller-than-two' => [$lengthTooSmall, '/length/i'];
        yield 'truncated-payload' => [$truncatedPayload, '/truncated/i'];
    }

    #[Test]
    #[DataProvider('provideInvalidSegments')]
    /**
     * Ensures invalid segment lengths and truncated payloads raise ParseError.
     *
     * @param string $jpeg           Binary JPEG fixture provided by the data set.
     * @param string $messagePattern Regular expression expected in the error message.
     */
    public function testInvalidLengthsAndUnexpectedEoiThrowParseError(string $jpeg, string $messagePattern): void
    {
        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($messagePattern);

        $extractor->extractExifBlobs();
    }

    /**
     * Verifies scanning stops at SOS and ignores restart markers during search.
     */
    #[Test]
    public function testStopsAtSosIgnoresRestartMarkers(): void
    {
        $primaryExif = 'primary-before-sos';
        $xmpXml      = '<x:xmpmeta xmlns:x="adobe:ns:meta/">BeforeSOS</x:xmpmeta>';
        $ignoredExif = 'ignored-after-sos';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $primaryExif)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml)
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'A'
            . "\xFF\xD0"
            . "\xFF" . chr(self::MARKER_APP1)
            . pack('n', strlen(self::EXIF_SIGNATURE . $ignoredExif) + 2)
            . self::EXIF_SIGNATURE . $ignoredExif
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$primaryExif], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Builds a JPEG binary by wrapping payload segments with SOI/EOI markers.
     */
    private static function jpeg(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
    }

    /**
     * Wraps a payload with a JPEG marker and two-byte length field.
     */
    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Creates a stream-backed extractor for an in-memory JPEG binary.
     */
    private function createExtractor(string $jpeg): JpegExtractor
    {
        $fh = fopen('php://temp', 'wb+');
        fwrite($fh, $jpeg);
        rewind($fh);

        return new JpegExtractor(new Stream($fh, strlen($jpeg)));
    }
}
