<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParser;
use MagicSunday\ImageMeta\Parse\Jpeg\MpfParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function array_values;
use function chr;
use function count;
use function file_put_contents;
use function fopen;
use function fwrite;
use function iconv;
use function implode;
use function pack;
use function rewind;
use function str_pad;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises the JPEG parser using synthetic marker segments and APP payloads.
 * It validates extraction of EXIF, XMP, ICC profiles, FlashPix, IPTC, MPF, and audio streams.
 * The suite includes malformed markers and length mismatches to confirm guardrail errors.
 * This ensures JPEG parsing remains resilient while preserving segment ordering and data.
 */
#[UsesClass(Stream::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ParseError::class)]
#[UsesTrait(NormalisesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[CoversClass(JpegParser::class)]
#[UsesClass(JpegAudioStream::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(MpfAttributes::class)]
#[UsesClass(MpfDocument::class)]
#[UsesClass(MpfEntry::class)]
#[UsesClass(MpfParser::class)]
final class JpegParserTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string TIFF_HEADER = "MM\x00\x2A";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string EXTENDED_XMP_SIGNATURE = "http://ns.adobe.com/xmp/extension/\0";

    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    private const string MPF_SIGNATURE = "MPF\0";

    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const string FPXR_SIGNATURE = 'FPXR';

    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    private const int MARKER_APP1 = 0xE1;

    private const int MARKER_APP2 = 0xE2;

    private const int MARKER_APP11 = 0xEB;

    private const int MARKER_APP13 = 0xED;

    private const int MARKER_DQT = 0xDB;

    private const int MARKER_DHT = 0xC4;

    private const int MARKER_DRI = 0xDD;

    private const int MARKER_SOF0 = 0xC0;

    private const int MARKER_SOF2 = 0xC2;

    private const int MARKER_SOS = 0xDA;

    /**
     * Extracts EXIF and XMP from APP1 segments in EXIF-compliant order.
     * This verifies payload extraction while preserving APP1 Exif placement rules.
     *
     * @param list<string> $segments
     * @param list<string> $expectedExif
     * @param list<string> $expectedXmp
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideApp1Variants')]
    public function extractsExifAndXmpInAnyOrder(array $segments, array $expectedExif, array $expectedXmp): void
    {
        /** @var list<string> $segments */
        $jpeg      = $this->jpeg(...$segments);
        $extractor = $this->createExtractor($jpeg);

        self::assertSame($expectedExif, $extractor->extractExifBlobs());
        self::assertSame($expectedXmp, $extractor->extractXmpPackets());
    }

    /**
     * Provides APP1 segment permutations mixing EXIF and XMP payloads.
     * These fixtures exercise the ordering logic in the extractor.
     *
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function provideApp1Variants(): iterable
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
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
     * Supplies two large EXIF segments that exceed 64 KB when combined.
     * This ensures multiple APP1 EXIF blobs are collected and returned intact.
     *
     * @return void
     */
    #[Test]
    public function largeExifOver64KbIsHandled(): void
    {
        $firstBlob  = self::TIFF_HEADER . str_repeat('A', 40_000);
        $secondBlob = self::TIFF_HEADER . str_repeat('B', 30_000);
        $xmpXml     = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Large</x:xmpmeta>';

        $jpeg = $this->jpeg(self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $firstBlob), self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $secondBlob), self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml));

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$firstBlob, $secondBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Repeats identical XMP segments alongside unique ones.
     * This verifies deduplication keeps only distinct XMP packets.
     *
     * @return void
     */
    #[Test]
    public function duplicateXmpSegmentsAreDeduplicated(): void
    {
        $xmpOne   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">One</x:xmpmeta>';
        $xmpTwo   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Two</x:xmpmeta>';
        $exifBlob = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifBlob),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpOne),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpOne),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpTwo),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpTwo)
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifBlob], $extractor->extractExifBlobs());
        self::assertSame([$xmpOne, $xmpTwo], $extractor->extractXmpPackets());
    }

    /**
     * Reassembles ExtendedXMP APP1 chunks referenced by xmpNote:HasExtendedXMP.
     * This verifies chunk ordering, concatenation, and merged packet emission.
     *
     * @return void
     */
    #[Test]
    public function reassemblesExtendedXmpChunksReferencedByBasePacket(): void
    {
        $guid            = '0123456789ABCDEF0123456789ABCDEF';
        $basePacket      = '<x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmpNote:HasExtendedXMP="' . $guid . '">BASE-';
        $extendedPayload = 'EXTENDED</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $basePacket),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 8, substr($extendedPayload, 8))),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 0, substr($extendedPayload, 0, 8))),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$basePacket . $extendedPayload], $extractor->extractXmpPackets());
    }

    /**
     * Rejects ExtendedXMP assemblies that contain missing byte ranges.
     *
     * @return void
     */
    #[Test]
    public function rejectsExtendedXmpWithMissingChunkRanges(): void
    {
        $guid            = '89ABCDEF0123456789ABCDEF01234567';
        $basePacket      = '<x:xmpmeta xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmpNote:HasExtendedXMP="' . $guid . '">BASE-';
        $extendedPayload = 'EXTENDED</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $basePacket),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 0, substr($extendedPayload, 0, 4))),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 8, substr($extendedPayload, 8))),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/ExtendedXMP.*missing|missing.*ExtendedXMP/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Rejects ExtendedXMP assemblies that contain overlapping chunk ranges.
     *
     * @return void
     */
    #[Test]
    public function rejectsExtendedXmpWithOverlappingChunkRanges(): void
    {
        $guid            = 'FEDCBA9876543210FEDCBA9876543210';
        $basePacket      = '<x:xmpmeta xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmpNote:HasExtendedXMP="' . $guid . '">BASE-';
        $extendedPayload = 'EXTENDED</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $basePacket),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 0, substr($extendedPayload, 0, 8))),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 6, substr($extendedPayload, 6))),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/ExtendedXMP.*overlap|overlap.*ExtendedXMP/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Rejects mismatched GUID combinations between base and extension packets.
     *
     * @return void
     */
    #[Test]
    public function rejectsExtendedXmpGuidMismatchBetweenBaseAndExtensions(): void
    {
        $baseGuid        = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $extensionGuid   = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
        $basePacket      = '<x:xmpmeta xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmpNote:HasExtendedXMP="' . $baseGuid . '">BASE-';
        $extendedPayload = 'EXTENDED</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $basePacket),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($extensionGuid, strlen($extendedPayload), 0, $extendedPayload)),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/HasExtendedXMP|GUID|ExtendedXMP/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Keeps regular APP1 XMP extraction unchanged when no base reference exists.
     *
     * @return void
     */
    #[Test]
    public function ignoresOrphanExtendedXmpChunksWithoutBaseReference(): void
    {
        $guid            = '13579BDF02468ACE13579BDF02468ACE';
        $xmpPacket       = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Plain</x:xmpmeta>';
        $extendedPayload = 'ORPHAN';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPacket),
            self::segment(self::MARKER_APP1, $this->extendedXmpPayload($guid, strlen($extendedPayload), 0, $extendedPayload)),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$xmpPacket], $extractor->extractXmpPackets());
    }

    /**
     * Interleaves unknown APP markers with EXIF and XMP segments.
     * This confirms unknown segments are skipped without affecting known payload extraction.
     *
     * @return void
     */
    #[Test]
    public function skipsUnknownAppMarkersWhileExtractingKnownMetadata(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $xmpXml      = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Meta</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(0xE3, 'vendor-one'),
            self::segment(0xE4, 'vendor-two'),
            self::segment(0xE5, 'vendor-three'),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
            self::segment(0xE6, 'vendor-four')
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Places EXIF APP2 before APP1 Exif.
     * This verifies APP2 ordering constraints relative to APP1 Exif.
     *
     * @return void
     */
    #[Test]
    public function exifApp2BeforeApp1ThrowsParseError(): void
    {
        $mpfPayload  = self::MPF_SIGNATURE . 'x';
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP2, $mpfPayload),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP2|APP1/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Places APP1 Exif after a preceding marker.
     * This verifies APP1 Exif must be first metadata marker after SOI.
     *
     * @return void
     */
    #[Test]
    public function exifApp1AfterOtherMarkerThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(0xE3, 'vendor'),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP1 Exif.*first metadata marker/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Uses compliant EXIF marker ordering for APP1 and APP2.
     * This protects regression behaviour for valid APP marker order.
     *
     * @return void
     */
    #[Test]
    public function compliantExifAppMarkerOrderParses(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $xmpXml      = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Meta</x:xmpmeta>';
        $fpxrPayload = $this->fpxrContentsListPayload([
            ['size' => 0, 'default' => 0x00, 'name' => '/Root/Stream0'],
        ]);
        $sofPayload = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";
        $sosPayload = "\x03\x01\x00\x02\x11\x03\x11";

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, $fpxrPayload),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_SOF0, $sofPayload),
            self::segment(self::MARKER_SOS, $sosPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    /**
     * Accepts APP, DQT, DHT, SOF and SOS in EXIF-conformant order.
     *
     * @return void
     */
    #[Test]
    public function exifConformanceAppDqtDhtSofSosOrderParses(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $sofPayload  = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";
        $sosPayload = "\x03\x01\x00\x02\x11\x03\x11";

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_SOF0, $sofPayload),
            self::segment(self::MARKER_SOS, $sosPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
    }

    /**
     * Rejects EXIF streams that reach SOS without a preceding DQT marker.
     *
     * @return void
     */
    #[Test]
    public function missingDqtBeforeSosThrowsParseErrorForExif(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $sofPayload  = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_SOF0, $sofPayload),
            self::segment(self::MARKER_SOS, "\x03\x01\x00\x02\x11\x03\x11"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1488);
        $this->expectExceptionMessageMatches('/requires DQT|no preceding DQT/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects EXIF streams that reach SOS without a preceding DHT marker.
     *
     * @return void
     */
    #[Test]
    public function missingDhtBeforeSosThrowsParseErrorForExif(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $sofPayload  = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_SOF0, $sofPayload),
            self::segment(self::MARKER_SOS, "\x03\x01\x00\x02\x11\x03\x11"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1489);
        $this->expectExceptionMessageMatches('/requires DHT|no preceding DHT/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects EXIF streams that reach SOS without any preceding SOF marker.
     *
     * @return void
     */
    #[Test]
    public function missingSofBeforeSosThrowsParseErrorForExif(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_SOS, "\x03\x01\x00\x02\x11\x03\x11"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1490);
        $this->expectExceptionMessageMatches('/requires SOF|no preceding SOF/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects EXIF streams that end at EOI without any SOS marker.
     *
     * @return void
     */
    #[Test]
    public function missingSosThrowsParseErrorForExif(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1487);
        $this->expectExceptionMessageMatches('/requires SOS.*without SOS|without SOS.*requires SOS/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects APP markers that appear after DQT or DHT structural markers.
     *
     * @return void
     */
    #[Test]
    public function dqtOrDhtBeforeFinalAppMarkerThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_APP2, 'late-app2'),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP marker.*after structural|after structural.*APP marker/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects duplicate DQT marker segments.
     *
     * @return void
     */
    #[Test]
    public function duplicateDqtThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DQT, "\x00"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/DQT.*duplicate|duplicate.*DQT/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects duplicate DHT marker segments.
     *
     * @return void
     */
    #[Test]
    public function duplicateDhtThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/DHT.*duplicate|duplicate.*DHT/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects duplicate DRI marker segments.
     *
     * @return void
     */
    #[Test]
    public function duplicateDriThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_DRI, "\x00\x01"),
            self::segment(self::MARKER_DRI, "\x00\x02"),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/DRI.*duplicate|duplicate.*DRI/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Accepts APP11 after APP1/APP2 and before structural markers.
     *
     * @return void
     */
    #[Test]
    public function app11AfterApp1AndApp2BeforeStructuralMarkersParses(): void
    {
        $exifPayload  = self::TIFF_HEADER . 'primary-exif';
        $app2Payload  = 'dummy-app2';
        $app11Payload = $this->app11Payload(
            $this->app11SuperboxWithContent('abcd', 'marker-order'),
        );
        $sofPayload = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, $app2Payload),
            self::segment(self::MARKER_APP11, $app11Payload),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_DHT, "\x00"),
            self::segment(self::MARKER_SOF0, $sofPayload),
            self::segment(self::MARKER_SOS, "\x03\x01\x00\x02\x11\x03\x11"),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
    }

    /**
     * Rejects APP11 when it appears before APP1/APP2 metadata markers.
     *
     * @return void
     */
    #[Test]
    public function app11BeforeApp1App2RegionThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app11       = $this->app11Payload(
            $this->app11SuperboxWithContent('abcd', 'marker-order'),
        );

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP11, $app11),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11.*APP1\\/APP2|APP1\\/APP2.*APP11/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Rejects APP11 when it appears after structural image markers.
     *
     * @return void
     */
    #[Test]
    public function app11AfterDqtThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app11       = $this->app11Payload(
            $this->app11SuperboxWithContent('abcd', 'marker-order'),
        );

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_DQT, "\x00"),
            self::segment(self::MARKER_APP11, $app11),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11.*structural|structural.*APP11/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Keeps APP1/APP2 parsing behavior unchanged when APP11 is absent.
     *
     * @return void
     */
    #[Test]
    public function app1App2ParsingWithoutApp11StillParses(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_DQT, "\x00"),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
    }

    /**
     * Detects and surfaces XML/XMP metadata carried inside APP11 JUMBF payloads.
     *
     * @return void
     */
    #[Test]
    public function app11JumbfXmlPayloadIsSurfacedAsXmpPacket(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $xmpPacket   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">APP11-XMP</x:xmpmeta>';
        $app11       = $this->app11Payload(
            $this->app11SuperboxWithContent('xml ', $xmpPacket),
        );

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$xmpPacket], $extractor->extractXmpPackets());
    }

    /**
     * Reassembles multi-segment APP11 transport payloads by sequence order.
     *
     * @return void
     */
    #[Test]
    public function app11TransportReassemblyMergesOrderedFragments(): void
    {
        $exifPayload   = self::TIFF_HEADER . 'primary-exif';
        $xmpPacket     = '<x:xmpmeta xmlns:x="adobe:ns:meta/">APP11-SEQ</x:xmpmeta>';
        $jumbfSuperbox = $this->app11SuperboxWithContent('xml ', $xmpPacket);
        $fragmentA     = substr($jumbfSuperbox, 0, 12);
        $fragmentB     = substr($jumbfSuperbox, 12, 10);
        $fragmentC     = substr($jumbfSuperbox, 22);
        $app11a        = $this->app11TransportPayload($fragmentA, 7, 1);
        $app11b        = $this->app11TransportPayload($fragmentB, 7, 2);
        $app11c        = $this->app11TransportPayload($fragmentC, 7, 3);

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11a),
            self::segment(self::MARKER_APP11, $app11b),
            self::segment(self::MARKER_APP11, $app11c),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$xmpPacket], $extractor->extractXmpPackets());
    }

    /**
     * Rejects APP11 transport payloads with missing sequence fragments.
     *
     * @return void
     */
    #[Test]
    public function app11TransportMissingSequenceThrowsParseError(): void
    {
        $exifPayload   = self::TIFF_HEADER . 'primary-exif';
        $jumbfSuperbox = $this->app11SuperboxWithContent('xml ', '<x:xmpmeta>missing</x:xmpmeta>');
        $fragmentA     = substr($jumbfSuperbox, 0, 10);
        $fragmentB     = substr($jumbfSuperbox, 10);
        $app11a        = $this->app11TransportPayload($fragmentA, 3, 1);
        $app11b        = $this->app11TransportPayload($fragmentB, 3, 3);

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11a),
            self::segment(self::MARKER_APP11, $app11b),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11.*missing sequence|missing sequence.*APP11/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Rejects APP11 transport payloads with duplicate sequence numbers.
     *
     * @return void
     */
    #[Test]
    public function app11TransportDuplicateSequenceThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app11a      = $this->app11TransportPayload('first', 9, 1);
        $app11b      = $this->app11TransportPayload('second', 9, 1);

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11a),
            self::segment(self::MARKER_APP11, $app11b),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11.*duplicate sequence|duplicate sequence.*APP11/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Rejects APP11 transport payloads when instance metadata is inconsistent.
     *
     * @return void
     */
    #[Test]
    public function app11TransportInconsistentInstanceMetadataThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app11a      = $this->app11TransportPayload('chunk-a', 5, 1, "JP\0\0");
        $app11b      = $this->app11TransportPayload('chunk-b', 5, 2, "JP\0\x01");

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11a),
            self::segment(self::MARKER_APP11, $app11b),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11.*inconsistent instance metadata|inconsistent instance metadata.*APP11/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Preserves APP1/APP2 metadata extraction when APP11 JUMBF metadata is present.
     *
     * @return void
     */
    #[Test]
    public function mixedApp1App2App11PreservesSupportedMetadataOutputs(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app1Xmp     = '<x:xmpmeta xmlns:x="adobe:ns:meta/">APP1-XMP</x:xmpmeta>';
        $app11Xmp    = '<x:xmpmeta xmlns:x="adobe:ns:meta/">APP11-XMP</x:xmpmeta>';
        $iccProfile  = 'icc-profile-data';
        $iccPayload  = self::ICC_SIGNATURE . "\x01\x01" . $iccProfile;
        $app11       = $this->app11Payload(
            $this->app11SuperboxWithContent('xml ', $app11Xmp),
        );

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $app1Xmp),
            self::segment(self::MARKER_APP2, $iccPayload),
            self::segment(self::MARKER_APP11, $app11),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$app1Xmp, $app11Xmp], $extractor->extractXmpPackets());
        self::assertSame($iccProfile, $extractor->getIccProfile());
    }

    /**
     * Rejects malformed APP11 payloads with truncated JUMBF box data.
     *
     * @return void
     */
    #[Test]
    public function malformedTruncatedApp11JumbfPayloadThrowsParseError(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $malformed   = "JP\0\0" . pack('n', 1) . pack('N', 1) . pack('N', 32) . 'jumbshort';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $malformed),
        );

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/APP11|JUMBF|truncated/i');

        $extractor->extractXmpPackets();
    }

    /**
     * Skips unknown APP11 JUMBF content boxes without failing extraction.
     *
     * @return void
     */
    #[Test]
    public function unknownApp11JumbfContentBoxIsSkippedSafely(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $app11       = $this->app11Payload(
            $this->app11SuperboxWithContent('abcd', 'not-xml-content'),
        );

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP2, 'dummy-app2'),
            self::segment(self::MARKER_APP11, $app11),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([], $extractor->extractXmpPackets());
    }

    /**
     * Provides ICC profile split across multiple APP2 segments.
     * This verifies segment buffering and reassembly of the full ICC profile.
     *
     * @return void
     */
    #[Test]
    public function iccProfileSegmentsAreMerged(): void
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
     * Supplies a Contents List segment followed by ordered Stream Data segments.
     * This confirms stream assembly via content-list index and absolute stream offsets.
     *
     * @return void
     */
    #[Test]
    public function flashPixSegmentsAreMerged(): void
    {
        $partOne = 'flashpix-part-one';
        $partTwo = 'flashpix-part-two';

        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => strlen($partOne . $partTwo), 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );

        $segment1 = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 2, 0, $partOne));
        $segment2 = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 2, 2, strlen($partOne), $partTwo));

        $jpeg = $this->jpeg($contents, $segment1, $segment2);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([0 => $partOne . $partTwo], $extractor->getFlashPixStreams());
    }

    /**
     * Uses two contents-list entries and multiple stream-data segments.
     * This verifies each stream is reconstructed independently by list index.
     *
     * @return void
     */
    #[Test]
    public function flashPixMultipleStreamsAreHandled(): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => strlen('stream-one'), 'default' => 0x20, 'name' => '/Root/Stream0'],
                ['size' => strlen('alpha-beta-gamma'), 'default' => 0x20, 'name' => '/Root/Stream1'],
            ]),
        );

        $streamOne  = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 1, 0, 'stream-one'));
        $streamTwoA = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 1, 3, 0, 'alpha-'));
        $streamTwoB = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 2, 3, 6, 'beta-'));
        $streamTwoC = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 3, 3, 11, 'gamma'));

        $jpeg = $this->jpeg($contents, $streamOne, $streamTwoA, $streamTwoB, $streamTwoC);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([
            0 => 'stream-one',
            1 => 'alpha-beta-gamma',
        ], $extractor->getFlashPixStreams());
    }

    /**
     * Adds both MU_LAW and PCM audio payloads in APP2 segments.
     * This verifies audio stream parsing and metadata decoding for each format.
     *
     * @return void
     */
    #[Test]
    public function audioSegmentsAreCollected(): void
    {
        $muLawData    = str_repeat("\x01\x02", 4);
        $pcmData      = pack('n*', 0x0102, 0x0304, 0x0506, 0x0708);
        $muLawSegment = self::segment(self::MARKER_APP2, $this->audioPayload(1, 1, 8_000, 8, $muLawData));
        $pcmSegment   = self::segment(self::MARKER_APP2, $this->audioPayload(0, 2, 44_100, 16, $pcmData));

        $jpeg      = $this->jpeg($muLawSegment . $pcmSegment);
        $extractor = $this->createExtractor($jpeg);

        $streams = $extractor->getAudioStreams();
        self::assertCount(2, $streams);

        $muLaw = $streams[0];
        self::assertSame('MU_LAW_PCM', $muLaw->format);
        self::assertSame(1, $muLaw->channels);
        self::assertSame(8_000, $muLaw->sampleRate);
        self::assertSame(8, $muLaw->bitDepth);
        self::assertSame('1.00', $muLaw->version);
        self::assertSame($muLawData, $muLaw->data);

        $pcm = $streams[1];
        self::assertSame('PCM', $pcm->format);
        self::assertSame(2, $pcm->channels);
        self::assertSame(44_100, $pcm->sampleRate);
        self::assertSame(16, $pcm->bitDepth);
        self::assertSame('1.00', $pcm->version);
        self::assertSame($pcmData, $pcm->data);
    }

    /**
     * Uses a PCM audio segment with an unsupported sample rate.
     * This asserts a ParseError is thrown for unsupported audio configurations.
     *
     * @return void
     */
    #[Test]
    public function audioSegmentWithUnsupportedSampleRateThrows(): void
    {
        $payload = self::segment(self::MARKER_APP2, $this->audioPayload(0, 1, 12_000, 16, str_repeat("\x00", 4)));
        $jpeg    = $this->jpeg($payload);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getAudioStreams();
    }

    /**
     * Uses a MU_LAW audio segment with a non-8kHz sample rate.
     * This verifies the MU_LAW constraints are enforced with a ParseError.
     *
     * @return void
     */
    #[Test]
    public function muLawAudioSegmentWithNonEightKilohertzSampleRateThrows(): void
    {
        $payload = self::segment(self::MARKER_APP2, $this->audioPayload(1, 1, 11_025, 8, str_repeat("\x00", 8)));
        $jpeg    = $this->jpeg($payload);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getAudioStreams();
    }

    /**
     * Splits an MPF payload across two APP2 segments.
     * This confirms the MPF parser receives a reassembled payload with expected entries.
     *
     * @return void
     */
    #[Test]
    public function mpfSegmentsAreBufferedAndParsed(): void
    {
        $mpfBody = $this->buildMpfPayload();
        $split   = 24;

        $segmentOne = self::segment(self::MARKER_APP2, self::MPF_SIGNATURE . substr($mpfBody, 0, $split));
        $segmentTwo = self::segment(self::MARKER_APP2, self::MPF_SIGNATURE . substr($mpfBody, $split));

        $jpeg = $this->jpeg($segmentOne, $segmentTwo);

        $extractor = $this->createExtractor($jpeg);

        $document = $extractor->getMpfDocument();

        self::assertNotNull($document);
        self::assertSame('0100', $document->version);
        self::assertSame(2, $document->imageCount);
        self::assertCount(2, $document->entries);

        $first = $document->entries[0];
        self::assertSame(0x80000001, $first->attributes);
        self::assertSame(12345, $first->imageSize);
        self::assertSame(1000, $first->dataOffset);

        $second = $document->entries[1];
        self::assertSame(0x00000002, $second->attributes);
        self::assertSame(54321, $second->imageSize);
        self::assertSame(2000, $second->dataOffset);
        self::assertSame(1, $second->dependentImage1);

        $attributes = $document->attributes;
        self::assertNotNull($attributes);
        self::assertSame(5, $attributes->totalFrames);
        self::assertSame(1, $attributes->individualImageNumber);
    }

    /**
     * Provides an MPF segment that contains only the signature.
     * This verifies the parser rejects missing MPF payload data.
     *
     * @return void
     */
    #[Test]
    public function mpfSegmentWithoutPayloadThrowsParseError(): void
    {
        $segment = self::segment(self::MARKER_APP2, self::MPF_SIGNATURE);
        $jpeg    = $this->jpeg($segment);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getMpfDocument();
    }

    /**
     * Emits stream data before a contents list segment.
     * This verifies APP2 FPXR ordering constraints are enforced.
     *
     * @return void
     */
    #[Test]
    public function flashPixStreamDataBeforeContentsListThrowsParseError(): void
    {
        $streamData = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 1, 0, 'data'));

        $jpeg = $this->jpeg($streamData);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getFlashPixStreams();
    }

    /**
     * References a contents-list index that does not exist.
     * This verifies stream-data index bounds are validated.
     *
     * @return void
     */
    #[Test]
    public function flashPixInvalidContentsListIndexThrowsParseError(): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => 8, 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );
        $invalidIndex = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 1, 1, 0, 'abcd'));

        $jpeg = $this->jpeg($contents, $invalidIndex);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getFlashPixStreams();
    }

    /**
     * Uses overlapping stream offsets for the same contents-list entry.
     * This verifies overlap detection for FPXR stream assembly.
     *
     * @return void
     */
    #[Test]
    public function flashPixOverlappingOffsetsThrowParseError(): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => 10, 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );
        $first  = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 2, 0, 'abcde'));
        $second = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 2, 2, 3, 'xyz'));

        $jpeg = $this->jpeg($contents, $first, $second);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);

        $extractor->getFlashPixStreams();
    }

    /**
     * Provides one stream with two ordered stream-data segments.
     * This preserves regression behaviour for simple single-stream payloads.
     *
     * @return void
     */
    #[Test]
    public function flashPixSimpleRepresentablePayloadStillParses(): void
    {
        $first  = 'first-';
        $second = 'second';
        $all    = $first . $second;

        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => strlen($all), 'default' => 0x20, 'name' => '/Root/Stream0'],
            ]),
        );
        $segmentOne = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 2, 0, $first));
        $segmentTwo = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 2, 2, strlen($first), $second));

        $jpeg = $this->jpeg($contents, $segmentOne, $segmentTwo);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([0 => $all], $extractor->getFlashPixStreams());
    }

    /**
     * Rejects invalid sequence metadata in FlashPix stream-data transport headers.
     *
     * @param int $sequenceNumber
     * @param int $sequenceCount
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidFlashPixSequenceRanges')]
    public function flashPixInvalidSequenceMetadataThrowsParseError(int $sequenceNumber, int $sequenceCount): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => 8, 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );
        $stream = self::segment(
            self::MARKER_APP2,
            $this->fpxrStreamDataPayload(0, $sequenceNumber, $sequenceCount, 0, 'abcd'),
        );

        $jpeg = $this->jpeg($contents, $stream);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/sequence|FlashPix/i');

        $extractor->getFlashPixStreams();
    }

    /**
     * @return iterable<string, array{0:int, 1:int}>
     */
    public static function provideInvalidFlashPixSequenceRanges(): iterable
    {
        yield 'sequence-number-zero' => [0, 2];
        yield 'sequence-number-above-count' => [3, 2];
        yield 'sequence-count-zero' => [1, 0];
    }

    /**
     * Rejects stream segments that advertise conflicting sequence counts.
     *
     * @return void
     */
    #[Test]
    public function flashPixConflictingSequenceCountThrowsParseError(): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => 12, 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );
        $first  = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 2, 0, 'first-'));
        $second = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 2, 3, 6, 'second'));

        $jpeg = $this->jpeg($contents, $first, $second);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/sequence count|FlashPix/i');

        $extractor->getFlashPixStreams();
    }

    /**
     * Rejects incomplete stream assemblies when declared sequence slots are missing.
     *
     * @return void
     */
    #[Test]
    public function flashPixMissingSequenceThrowsParseError(): void
    {
        $contents = self::segment(
            self::MARKER_APP2,
            $this->fpxrContentsListPayload([
                ['size' => 12, 'default' => 0x00, 'name' => '/Root/Stream0'],
            ]),
        );
        $first = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 1, 3, 0, 'first-'));
        $third = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 3, 3, 6, 'third-'));

        $jpeg = $this->jpeg($contents, $first, $third);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/missing sequence|FlashPix/i');

        $extractor->getFlashPixStreams();
    }

    /**
     * Keeps non-FPXR metadata extraction unchanged after strict FPXR sequence validation.
     *
     * @return void
     */
    #[Test]
    public function nonFlashPixJpegParsingRemainsUnchanged(): void
    {
        $exifPayload = self::TIFF_HEADER . 'primary-exif';
        $xmpPayload  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">No FlashPix</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPayload),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpPayload], $extractor->extractXmpPackets());
        self::assertSame([], $extractor->getFlashPixStreams());
    }

    /**
     * Rejects malformed FPXR ID headers before segment body parsing.
     *
     * @param string $payload FPXR APP2 payload including malformed ID header.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidFlashPixIdHeaders')]
    public function invalidFlashPixIdHeaderThrowsParseError(string $payload): void
    {
        $jpeg = $this->jpeg(self::segment(self::MARKER_APP2, $payload));

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/FPXR|FlashPix/i');

        $extractor->getFlashPixStreams();
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function provideInvalidFlashPixIdHeaders(): iterable
    {
        yield 'missing-nul-after-signature' => [
            self::FPXR_SIGNATURE . "\x01\x00\x00\x00",
        ];

        yield 'unsupported-version-byte' => [
            self::FPXR_SIGNATURE . "\x00\x01\x00\x00",
        ];

        yield 'short-id-header' => [
            self::FPXR_SIGNATURE . "\x00",
        ];
    }

    /**
     * Embeds two IPTC APP13 payloads with different values.
     * This verifies IPTC payloads are collected as raw blobs.
     *
     * @return void
     */
    #[Test]
    public function iptcIsCollectedRaw(): void
    {
        $iimOne  = $this->iimDataset(2, 5, 'Object One');
        $iimTwo  = $this->iimDataset(2, 5, 'Object Two');
        $iptcOne = self::IPTC_SIGNATURE . $this->resourceBlock(0x0404, $iimOne);
        $iptcTwo = self::IPTC_SIGNATURE . $this->resourceBlock(0x0404, $iimTwo);

        $jpeg = $this->jpeg(self::segment(self::MARKER_APP13, $iptcOne), self::segment(self::MARKER_APP13, $iptcTwo));

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$iptcOne, $iptcTwo], $extractor->getIptcPayloads());
    }

    /**
     * Writes a JPEG to disk and parses it via Stream::fromPath.
     * This ensures file-backed streams behave the same as in-memory buffers.
     *
     * @return void
     */
    #[Test]
    public function extractsAppSegmentsFromFilesystemStream(): void
    {
        $exifPayload = self::TIFF_HEADER . 'filesystem-exif';
        $xmpPayload  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Filesystem</x:xmpmeta>';

        $jpeg = $this->jpeg(
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPayload),
        );

        $path = tempnam(sys_get_temp_dir(), 'imagemeta-jpeg-');
        if ($path === false) {
            self::fail('Unable to allocate temporary path for JPEG extractor regression test.');
        }

        if (file_put_contents($path, $jpeg) === false) {
            self::fail('Unable to write JPEG payload to temporary path.');
        }

        try {
            $stream    = Stream::fromPath($path);
            $extractor = new JpegParser($stream);

            self::assertSame([$exifPayload], $extractor->extractExifBlobs());
            self::assertSame([$xmpPayload], $extractor->extractXmpPackets());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Builds a SOF frame payload with explicit component sampling factors.
     * Verifies the parser extracts precision, dimensions, and derived YCbCr subsampling.
     *
     * @param int $marker
     *
     * @return void
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
     * Uses sampling factors that yield illegal and reserved subsampling ratios.
     * Ensures the derived YCbCr subsampling is rejected and returns null.
     *
     * @return void
     */
    #[Test]
    public function derivedYCbCrSubSamplingRejectsIllegalValues(): void
    {
        // Test illegal subsampling: [3,2] should be rejected
        // Luma: 3H×2V, Chroma: 1H×1V → 3/1=3, 2/1=2 → [3,2] is illegal
        $framePayloadIllegal = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x32\x00" // Component 1 (Y):  3H×2V
            . "\x02\x11\x01" // Component 2 (Cb): 1H×1V
            . "\x03\x11\x01"; // Component 3 (Cr): 1H×1V

        $jpegIllegal      = $this->jpeg(self::segment(self::MARKER_SOF0, $framePayloadIllegal));
        $extractorIllegal = $this->createExtractor($jpegIllegal);

        // Should return null for illegal subsampling values
        self::assertNull($extractorIllegal->getFrameYCbCrSubSampling());

        // Test reserved subsampling: [4,1] should be rejected per EXIF 3.0 §4.6.5.1.12
        // Luma: 4H×1V, Chroma: 1H×1V → 4/1=4, 1/1=1 → [4,1] is reserved
        $framePayloadReserved41 = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x41\x00" // Component 1 (Y):  4H×1V
            . "\x02\x11\x01" // Component 2 (Cb): 1H×1V
            . "\x03\x11\x01"; // Component 3 (Cr): 1H×1V

        $jpegReserved41      = $this->jpeg(self::segment(self::MARKER_SOF0, $framePayloadReserved41));
        $extractorReserved41 = $this->createExtractor($jpegReserved41);

        self::assertNull($extractorReserved41->getFrameYCbCrSubSampling());

        // Test reserved subsampling: [4,4] should be rejected per EXIF 3.0 §4.6.5.1.12
        // Luma: 4H×4V, Chroma: 1H×1V → 4/1=4, 4/1=4 → [4,4] is reserved
        $framePayloadReserved44 = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x44\x00" // Component 1 (Y):  4H×4V
            . "\x02\x11\x01" // Component 2 (Cb): 1H×1V
            . "\x03\x11\x01"; // Component 3 (Cr): 1H×1V

        $jpegReserved44      = $this->jpeg(self::segment(self::MARKER_SOF0, $framePayloadReserved44));
        $extractorReserved44 = $this->createExtractor($jpegReserved44);

        self::assertNull($extractorReserved44->getFrameYCbCrSubSampling());

        // Test legal subsampling: [2,1]
        // Luma: 2H×1V, Chroma: 1H×1V → 2/1=2, 1/1=1 → [2,1] is legal (YCbCr4:2:2)
        $framePayloadLegal21 = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x21\x00" // Component 1 (Y):  2H×1V
            . "\x02\x11\x01" // Component 2 (Cb): 1H×1V
            . "\x03\x11\x01"; // Component 3 (Cr): 1H×1V

        $jpegLegal21      = $this->jpeg(self::segment(self::MARKER_SOF0, $framePayloadLegal21));
        $extractorLegal21 = $this->createExtractor($jpegLegal21);

        self::assertSame([2, 1], $extractorLegal21->getFrameYCbCrSubSampling());
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideSofMarkers(): iterable
    {
        yield 'baseline-dct' => [self::MARKER_SOF0];
    }

    /**
     * Rejects progressive SOF2 markers in strict EXIF-JPEG conformance mode.
     *
     * @return void
     */
    #[Test]
    public function rejectsProgressiveSof2InStrictExifMode(): void
    {
        $framePayload = "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";

        $jpeg      = $this->jpeg(self::segment(self::MARKER_SOF2, $framePayload));
        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1486);
        $this->expectExceptionMessageMatches('/SOF2.*strict EXIF|strict EXIF.*SOF2/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Provides malformed JPEG structures expected to raise parse errors.
     * Each fixture triggers a different guardrail in segment length handling.
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
        yield 'flashpix-short-header' => [$shortFlashPix, '/FlashPix/i'];
    }

    /**
     * Parses malformed JPEG fixtures and asserts the ParseError message matches.
     * This verifies guardrails for short lengths, truncation, and FlashPix header errors.
     *
     * @param string $jpeg           Binary JPEG fixture provided by the data set.
     * @param string $messagePattern Regular expression expected in the error message.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideInvalidSegments')]
    public function invalidLengthsAndUnexpectedEoiThrowParseError(string $jpeg, string $messagePattern): void
    {
        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($messagePattern);

        $extractor->extractExifBlobs();
    }

    /**
     * Accepts scan data with DRI when at least one restart marker appears before EOI.
     * This verifies stream-level DRI/RST conformance without changing metadata extraction.
     *
     * @return void
     */
    #[Test]
    public function acceptsDriWhenScanDataContainsRestartMarker(): void
    {
        $exifPayload = self::TIFF_HEADER . 'pre-sos-exif';
        $xmpPayload  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">DRI-RST</x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . self::segment(self::MARKER_DRI, pack('n', 8))
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'scan'
            . "\xFF\xD0"
            . 'tail'
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpPayload], $extractor->extractXmpPackets());
    }

    /**
     * Rejects JPEG scan data without restart markers when DRI is declared.
     *
     * @return void
     */
    #[Test]
    public function rejectsDriWhenScanDataContainsNoRestartMarkers(): void
    {
        $exifPayload = self::TIFF_HEADER . 'pre-sos-exif';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . self::segment(self::MARKER_DRI, pack('n', 8))
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'scan'
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1485);
        $this->expectExceptionMessageMatches('/DRI.*restart|restart.*DRI/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Accepts scan data when SOS is followed by a valid EOI marker.
     * This verifies metadata extraction remains unchanged for conformant streams.
     *
     * @return void
     */
    #[Test]
    public function acceptsSosStreamWhenEoiMarkerIsPresent(): void
    {
        $exifPayload = self::TIFF_HEADER . 'pre-sos-exif';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'scan'
            . "\xFF\xD9";

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
    }

    /**
     * Rejects scan data streams that end without an EOI marker after SOS.
     *
     * @return void
     */
    #[Test]
    public function rejectsSosStreamWithoutEoiMarker(): void
    {
        $exifPayload = self::TIFF_HEADER . 'pre-sos-exif';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . "\xFF\x00" . 'scan';

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1484);
        $this->expectExceptionMessageMatches('/SOS.*without EOI|without EOI.*SOS/i');

        $extractor->extractExifBlobs();
    }

    /**
     * Inserts a Start of Scan marker followed by restart markers and extra APP data.
     * This confirms parsing stops at SOS and ignores metadata after scan data begins.
     *
     * @return void
     */
    #[Test]
    public function stopsAtSosIgnoresRestartMarkers(): void
    {
        $primaryExif = self::TIFF_HEADER . 'primary-before-sos';
        $xmpXml      = '<x:xmpmeta xmlns:x="adobe:ns:meta/">BeforeSOS</x:xmpmeta>';
        $ignoredExif = self::TIFF_HEADER . 'ignored-after-sos';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $primaryExif)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
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
     * Places valid APP segments before EOI and additional segments after EOI.
     * This verifies segments after EOI are ignored while earlier metadata is preserved.
     *
     * @return void
     */
    #[Test]
    public function ignoresSegmentsAfterEoi(): void
    {
        $exifPayload  = self::TIFF_HEADER . 'pre-eoi-exif';
        $xmpPayload   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">PreEOI</x:xmpmeta>';
        $iccProfile   = 'icc-profile';
        $postExifData = self::TIFF_HEADER . 'ignored-post-eoi';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPayload)
            . self::segment(self::MARKER_APP2, self::ICC_SIGNATURE . "\x01\x01" . $iccProfile)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . 'scan-data'
            . "\xFF\xD9"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $postExifData)
            . self::segment(self::MARKER_APP2, self::ICC_SIGNATURE . "\x02\x02" . 'ignored')
            . "\xFF\x00" . 'padding';

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpPayload], $extractor->extractXmpPackets());
        self::assertSame($iccProfile, $extractor->getIccProfile());
    }

    /**
     * Adds malformed trailing bytes after EOI with an oversized length field.
     * This confirms the parser ignores post-EOI garbage without raising errors.
     *
     * @return void
     */
    #[Test]
    public function skipsMalformedTrailingDataAfterEoi(): void
    {
        $exifPayload = self::TIFF_HEADER . 'pre-eoi-exif';
        $xmpPayload  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Trailing</x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpPayload)
            . self::segment(self::MARKER_DQT, "\x00")
            . self::segment(self::MARKER_DHT, "\x00")
            . self::segment(self::MARKER_SOF0, $this->defaultSofPayload())
            . "\xFF\xDA" . pack('n', 8) . "\x03\x01\x00\x02\x11\x03"
            . 'entropy'
            . "\xFF\xD9"
            . "\xFF\xE1\xFF\xFF" // Advertised oversized length after EOI should be ignored
            . "\x00\x01";

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpPayload], $extractor->extractXmpPackets());
    }

    /**
     * Builds a JPEG binary by wrapping payload segments with SOI/EOI markers.
     * This helper keeps fixture construction concise across tests.
     *
     * @param string ...$segments
     *
     * @return string
     */
    private function jpeg(string ...$segments): string
    {
        /** @var list<string> $segmentList */
        $segmentList = array_values($segments);

        if ($this->containsExifApp1Segment($segmentList) && !$this->containsMarkerSegment($segmentList, self::MARKER_SOS)) {
            if (!$this->containsMarkerSegment($segmentList, self::MARKER_DQT)) {
                $segmentList[] = self::segment(self::MARKER_DQT, "\x00");
            }

            if (!$this->containsMarkerSegment($segmentList, self::MARKER_DHT)) {
                $segmentList[] = self::segment(self::MARKER_DHT, "\x00");
            }

            if (
                !$this->containsMarkerSegment($segmentList, self::MARKER_SOF0)
                && !$this->containsMarkerSegment($segmentList, self::MARKER_SOF2)
            ) {
                $segmentList[] = self::segment(self::MARKER_SOF0, $this->defaultSofPayload());
            }

            $segmentList[] = self::segment(self::MARKER_SOS, "\x03\x01\x00\x02\x11\x03\x11");
            $segmentList[] = 'scan';
        }

        return "\xFF\xD8" . implode('', $segmentList) . "\xFF\xD9";
    }

    /**
     * @param list<string> $segments
     */
    private function containsExifApp1Segment(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (!$this->containsMarkerPrefix($segment, self::MARKER_APP1)) {
                continue;
            }

            if (substr($segment, 4, strlen(self::EXIF_SIGNATURE)) === self::EXIF_SIGNATURE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $segments
     */
    private function containsMarkerSegment(array $segments, int $marker): bool
    {
        return array_any($segments, fn (string $segment): bool => $this->containsMarkerPrefix($segment, $marker));
    }

    private function containsMarkerPrefix(string $segment, int $marker): bool
    {
        return str_starts_with($segment, "\xFF" . chr($marker));
    }

    private function defaultSofPayload(): string
    {
        return "\x08" . pack('n', 32) . pack('n', 64) . "\x03"
            . "\x01\x22\x00"
            . "\x02\x11\x01"
            . "\x03\x11\x01";
    }

    /**
     * Wraps a payload with a JPEG marker and two-byte length field.
     * This helper standardizes APP segment construction for tests.
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
     * Builds an ExtendedXMP APP1 payload with GUID, full-length and chunk offset headers.
     *
     * @param string $guid       32-char uppercase hex GUID.
     * @param int    $fullLength Total logical ExtendedXMP payload length.
     * @param int    $offset     Byte offset of this chunk inside the logical payload.
     * @param string $chunk      Chunk bytes for this segment.
     *
     * @return string
     */
    private function extendedXmpPayload(string $guid, int $fullLength, int $offset, string $chunk): string
    {
        return self::EXTENDED_XMP_SIGNATURE
            . $guid
            . pack('N', $fullLength)
            . pack('N', $offset)
            . $chunk;
    }

    /**
     * Builds an APP11 payload wrapper around a JUMBF superbox.
     *
     * @param string $jumbfSuperbox Serialized JUMBF superbox bytes.
     * @param int    $instance      APP11 box-instance number.
     * @param int    $sequence      APP11 packet sequence number.
     *
     * @return string APP11 payload bytes.
     */
    private function app11Payload(string $jumbfSuperbox, int $instance = 1, int $sequence = 1): string
    {
        return $this->app11TransportPayload($jumbfSuperbox, $instance, $sequence);
    }

    /**
     * Builds an APP11 transport payload with explicit identifier, instance, and sequence.
     *
     * @param string $transportData Raw APP11 transport chunk data.
     * @param int    $instance      APP11 box-instance number.
     * @param int    $sequence      APP11 packet sequence number.
     * @param string $identifier    APP11 identifier field (4 bytes).
     *
     * @return string APP11 payload bytes.
     */
    private function app11TransportPayload(
        string $transportData,
        int $instance,
        int $sequence,
        string $identifier = "JP\0\0",
    ): string {
        return $identifier . pack('n', $instance) . pack('N', $sequence) . $transportData;
    }

    /**
     * Builds a minimal JUMBF superbox with description and content child boxes.
     *
     * @param string $contentBoxType Four-character content box type.
     * @param string $contentPayload Content box payload bytes.
     *
     * @return string Serialized JUMBF superbox.
     */
    private function app11SuperboxWithContent(string $contentBoxType, string $contentPayload): string
    {
        $description = $this->jumbfBox('jumd', str_repeat("\0", 16));
        $content     = $this->jumbfBox($contentBoxType, $contentPayload);

        return $this->jumbfBox('jumb', $description . $content);
    }

    /**
     * Builds an ISO-BMFF-style box payload used by JUMBF.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Box payload bytes.
     *
     * @return string Serialized box.
     */
    private function jumbfBox(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8) . $type . $payload;
    }

    private function resourceBlock(int $resourceId, string $data, string $name = ''): string
    {
        $nameLength = strlen($name);
        $nameField  = chr($nameLength) . $name;
        if ((strlen($nameField) % 2) !== 0) {
            $nameField .= "\0";
        }

        $block = '8BIM'
            . pack('n', $resourceId)
            . $nameField
            . pack('N', strlen($data))
            . $data;

        if ((strlen($data) % 2) !== 0) {
            $block .= "\0";
        }

        return $block;
    }

    private function iimDataset(int $record, int $dataset, string $value): string
    {
        return "\x1C" . chr($record) . chr($dataset) . pack('n', strlen($value)) . $value;
    }

    /**
     * Builds a synthetic MPF payload containing two entries and attribute metadata.
     * This fixture is used to validate MPF segment buffering and parsing.
     */
    private function buildMpfPayload(): string
    {
        $entries = [
            $this->mpfEntry(0x80000001, 12345, 1000, 0, 0),
            $this->mpfEntry(0x00000002, 54321, 2000, 1, 0),
        ];

        $entryData  = implode('', $entries);
        $imageCount = count($entries);

        $header          = 'II' . pack('v', 42) . pack('V', 8);
        $entryCount      = 3;
        $indexIfdLength  = 2 + ($entryCount * 12) + 4;
        $mpEntryOffset   = 8 + $indexIfdLength;
        $attributeOffset = $mpEntryOffset + strlen($entryData);

        $indexIfd = pack('v', $entryCount)
            . $this->mpfIfdEntry(0xB000, 2, 4, '0100')
            . $this->mpfIfdEntry(0xB001, 4, 1, pack('V', $imageCount))
            . $this->mpfIfdEntry(0xB002, 7, strlen($entryData), offset: $mpEntryOffset)
            . pack('V', $attributeOffset);

        $attributeIfd = pack('v', 2)
            . $this->mpfIfdEntry(0xB004, 4, 1, pack('V', 5))
            . $this->mpfIfdEntry(0xB005, 4, 1, pack('V', 1))
            . pack('V', 0);

        return $header . $indexIfd . $entryData . $attributeIfd;
    }

    private function mpfEntry(int $attributes, int $size, int $offset, int $dependent1, int $dependent2): string
    {
        return pack('V', $attributes)
            . pack('V', $size)
            . pack('V', $offset)
            . pack('v', $dependent1)
            . pack('v', $dependent2);
    }

    private function mpfIfdEntry(int $tag, int $type, int $count, string $value = '', ?int $offset = null): string
    {
        $entry = pack('v', $tag) . pack('v', $type) . pack('V', $count);

        if ($offset !== null) {
            return $entry . pack('V', $offset);
        }

        $typeSizes = [
            1 => 1,
            2 => 1,
            3 => 2,
            4 => 4,
            5 => 8,
            7 => 1,
        ];

        $byteCount = ($typeSizes[$type] ?? 1) * $count;
        $padded    = str_pad(substr($value, 0, $byteCount), 4, "\0");

        return $entry . $padded;
    }

    /**
     * Builds a FlashPix Contents List APP2 payload with one or more entries.
     *
     * @param list<array{size:int, default:int, name:string}> $entries
     */
    private function fpxrContentsListPayload(array $entries): string
    {
        $payload = pack('n', count($entries));

        foreach ($entries as $entry) {
            $nameBytes = $this->utf16LeNullTerminated($entry['name']);

            $payload .= pack('N', $entry['size']);
            $payload .= chr($entry['default']);
            $payload .= $nameBytes;
        }

        return self::FPXR_SIGNATURE . "\x00\x00" . $payload;
    }

    /**
     * Builds a FlashPix Stream Data APP2 payload for one contents-list entry.
     */
    private function fpxrStreamDataPayload(
        int $contentsIndex,
        int $sequenceNumber,
        int $sequenceCount,
        int $offset,
        string $data,
    ): string {
        return self::FPXR_SIGNATURE
            . "\x00\x00"
            . pack('nnnN', $contentsIndex, $sequenceNumber, $sequenceCount, $offset)
            . $data;
    }

    private function utf16LeNullTerminated(string $text): string
    {
        $encoded = iconv('UTF-8', 'UTF-16LE', $text);
        if ($encoded === false) {
            self::fail('Unable to encode UTF-16LE FlashPix test path.');
        }

        return $encoded . "\x00\x00";
    }

    /**
     * Builds an EXIF audio APP2 payload with the provided metadata fields.
     * It computes sample count for PCM formats and appends the raw audio data.
     */
    private function audioPayload(int $format, int $channels, int $sampleRate, int $bitDepth, string $data): string
    {
        $sampleCount = 0;
        if ($format !== 2) {
            $bytesPerSample = (int) (($bitDepth / 8) * $channels);
            if ($bytesPerSample > 0) {
                $sampleCount = (int) (strlen($data) / $bytesPerSample);
            }
        }

        $header = self::AUDIO_SIGNATURE
            . chr(1) // major version
            . chr(0) // minor version
            . chr($format)
            . chr($channels)
            . pack('N', $sampleRate)
            . chr($bitDepth)
            . pack('N', $sampleCount);

        return $header . $data;
    }

    /**
     * Creates a stream-backed extractor for an in-memory JPEG binary.
     * This helper keeps parser instantiation consistent across tests.
     *
     * @param string $jpeg
     *
     * @return JpegParser
     */
    private function createExtractor(string $jpeg): JpegParser
    {
        $fh = fopen('php://temp', 'wb+');
        if ($fh === false) {
            self::fail('Unable to open temporary stream for JPEG test data.');
        }

        fwrite($fh, $jpeg);
        rewind($fh);

        return new JpegParser(new Stream($fh, strlen($jpeg)));
    }
}
