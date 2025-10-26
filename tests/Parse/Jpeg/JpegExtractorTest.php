<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function fopen;
use function fwrite;
use function implode;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * Exercises the JPEG extractor using synthetic marker segments.
 *
 * @covers \MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor
 */
final class JpegExtractorTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const string FPXR_SIGNATURE = 'FPXR';

    private const int MARKER_APP1 = 0xE1;

    private const int MARKER_APP2 = 0xE2;

    private const int MARKER_APP13 = 0xED;

    private const int MARKER_SOF0 = 0xC0;

    private const int MARKER_SOF2 = 0xC2;

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
        $jpeg      = $this->jpeg(...$segments);
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

        $jpeg = $this->jpeg(self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $firstBlob), self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $secondBlob), self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml));

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$firstBlob, $secondBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Ensures duplicate XMP APP1 segments are ignored while keeping order stable.
     */
    #[Test]
    public function testDuplicateXmpSegmentsAreDeduplicated(): void
    {
        $xmpOne   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">One</x:xmpmeta>';
        $xmpTwo   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Two</x:xmpmeta>';
        $exifBlob = 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpOne),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifBlob),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpOne),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpTwo),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpTwo)
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpOne, $xmpTwo], $extractor->extractXmpPackets());
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

        $jpeg = $this->jpeg(self::segment(self::MARKER_APP2, $segment1Payload), self::segment(self::MARKER_APP2, $segment2Payload));

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$segment1Payload, $segment2Payload], $extractor->getIccSegments());
        self::assertSame($iccPart1 . $iccPart2, $extractor->getIccProfile());
    }

    /**
     * Confirms FlashPix APP2 segments are reordered and merged per stream identifier.
     */
    #[Test]
    public function testFlashPixSegmentsAreMerged(): void
    {
        $streamId = 3;
        $partOne  = 'flashpix-part-one';
        $partTwo  = 'flashpix-part-two';

        $segment1 = self::segment(self::MARKER_APP2, self::fpxrPayload($streamId, 1, 2, $partOne));
        $segment2 = self::segment(self::MARKER_APP2, self::fpxrPayload($streamId, 2, 2, $partTwo));

        $jpeg = $this->jpeg($segment2, $segment1);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$streamId => $partOne . $partTwo], $extractor->getFlashPixStreams());
    }

    /**
     * Ensures multiple FlashPix streams are merged independently and keyed by identifier.
     */
    #[Test]
    public function testFlashPixMultipleStreamsAreHandled(): void
    {
        $streamOne = self::segment(self::MARKER_APP2, self::fpxrPayload(1, 1, 1, 'stream-one'));
        $streamTwoA = self::segment(self::MARKER_APP2, self::fpxrPayload(2, 1, 3, 'alpha-'));
        $streamTwoB = self::segment(self::MARKER_APP2, self::fpxrPayload(2, 2, 3, 'beta-'));
        $streamTwoC = self::segment(self::MARKER_APP2, self::fpxrPayload(2, 3, 3, 'gamma'));

        $jpeg = $this->jpeg($streamTwoB, $streamOne, $streamTwoC, $streamTwoA);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([
            1 => 'stream-one',
            2 => 'alpha-beta-gamma',
        ], $extractor->getFlashPixStreams());
    }

    /**
     * Ensures inconsistent FlashPix sequence counts discard accumulated fragments.
     */
    #[Test]
    public function testFlashPixInvalidSequenceDiscardsStream(): void
    {
        $segment1 = self::segment(self::MARKER_APP2, self::fpxrPayload(5, 1, 2, 'first'));
        $segment2 = self::segment(self::MARKER_APP2, self::fpxrPayload(5, 2, 3, 'second'));

        $jpeg = $this->jpeg($segment1, $segment2);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([], $extractor->getFlashPixStreams());
    }

    /**
     * Ensures APP13 segments with the Photoshop signature are stored verbatim.
     */
    #[Test]
    public function testIptcIsCollectedRaw(): void
    {
        $iptcOne = self::IPTC_SIGNATURE . 'payload-one';
        $iptcTwo = self::IPTC_SIGNATURE . 'payload-two';

        $jpeg = $this->jpeg(self::segment(self::MARKER_APP13, $iptcOne), self::segment(self::MARKER_APP13, $iptcTwo));

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$iptcOne, $iptcTwo], $extractor->getIptcPayloads());
    }

    /**
     * Ensures SOF markers expose precision and component sampling factors.
     *
     * @param int $marker
     */
    #[Test]
    #[DataProvider('provideSofMarkers')]
    public function parsesPrecisionAndSamplingFromSof(int $marker): void
    {
        $framePayload = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";

        $jpeg      = $this->jpeg(self::segment($marker, $framePayload));
        $extractor = $this->createExtractor($jpeg);

        self::assertSame(8, $extractor->getFrameSamplePrecision());
        self::assertSame(32, $extractor->getFrameHeight());
        self::assertSame(64, $extractor->getFrameWidth());
        self::assertSame(
            [
                1 => ['horizontal' => 2, 'vertical' => 2],
                2 => ['horizontal' => 1, 'vertical' => 1],
                3 => ['horizontal' => 1, 'vertical' => 1],
            ],
            $extractor->getFrameComponentSamplingFactors(),
        );
        self::assertSame([2, 2], $extractor->getFrameYCbCrSubSampling());
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideSofMarkers(): iterable
    {
        yield 'baseline-dct' => [self::MARKER_SOF0];
        yield 'progressive-dct' => [self::MARKER_SOF2];
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
        $shortFlashPix    = "\xFF\xD8" . self::segment(self::MARKER_APP2, self::FPXR_SIGNATURE . "\x00\x01\x02") . "\xFF\xD9";

        yield 'length-smaller-than-two' => [$lengthTooSmall, '/length/i'];
        yield 'truncated-payload' => [$truncatedPayload, '/truncated/i'];
        yield 'flashpix-short-header' => [$shortFlashPix, '/FlashPix segment/i'];
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
     *
     * @param list<string> $segments
     *
     * @return string
     */
    private function jpeg(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
    }

    /**
     * Wraps a payload with a JPEG marker and two-byte length field.
     *
     * @param int    $marker
     * @param string $payload
     *
     * @return string
     */
    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Builds a FlashPix APP2 payload with the provided header parameters.
     */
    private static function fpxrPayload(int $streamId, int $sequence, int $count, string $data): string
    {
        return self::FPXR_SIGNATURE . pack('n', $streamId) . chr($sequence) . chr($count) . $data;
    }

    /**
     * Creates a stream-backed extractor for an in-memory JPEG binary.
     *
     * @param string $jpeg
     *
     * @return JpegExtractor
     */
    private function createExtractor(string $jpeg): JpegExtractor
    {
        $fh = fopen('php://temp', 'wb+');
        fwrite($fh, $jpeg);
        rewind($fh);

        return new JpegExtractor(new Stream($fh, strlen($jpeg)));
    }
}
