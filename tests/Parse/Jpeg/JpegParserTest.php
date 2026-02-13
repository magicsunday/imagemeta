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

    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    private const string MPF_SIGNATURE = "MPF\0";

    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const string FPXR_SIGNATURE = 'FPXR';

    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    private const int MARKER_APP1 = 0xE1;

    private const int MARKER_APP2 = 0xE2;

    private const int MARKER_APP13 = 0xED;

    private const int MARKER_SOF0 = 0xC0;

    private const int MARKER_SOF2 = 0xC2;

    /**
     * Extracts EXIF and XMP from APP1 segments in different orders.
     * This verifies both payload types are found regardless of segment ordering.
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
            self::segment(0xE3, 'vendor-one'),
            self::segment(0xE4, 'vendor-two'),
            self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $exifPayload),
            self::segment(0xE5, 'vendor-three'),
            self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmpXml),
            self::segment(0xE6, 'vendor-four')
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$exifPayload], $extractor->extractExifBlobs());
        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
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

        $segment1 = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 0, $partOne));
        $segment2 = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, strlen($partOne), $partTwo));

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

        $streamOne  = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 0, 'stream-one'));
        $streamTwoA = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 0, 'alpha-'));
        $streamTwoB = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 6, 'beta-'));
        $streamTwoC = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 11, 'gamma'));

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
        $streamData = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 0, 'data'));

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
        $invalidIndex = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(1, 0, 'abcd'));

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
        $first  = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 0, 'abcde'));
        $second = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 3, 'xyz'));

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
        $segmentOne = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, 0, $first));
        $segmentTwo = self::segment(self::MARKER_APP2, $this->fpxrStreamDataPayload(0, strlen($first), $second));

        $jpeg = $this->jpeg($contents, $segmentOne, $segmentTwo);

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([0 => $all], $extractor->getFlashPixStreams());
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
        yield 'progressive-dct' => [self::MARKER_SOF2];
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
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
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
    private function fpxrStreamDataPayload(int $contentsIndex, int $offset, string $data): string
    {
        return self::FPXR_SIGNATURE . "\x00\x00" . pack('nN', $contentsIndex, $offset) . $data;
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
