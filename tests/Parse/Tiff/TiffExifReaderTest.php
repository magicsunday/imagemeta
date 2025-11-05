<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function array_map;
use function count;
use function implode;
use function is_int;
use function ord;
use function pack;
use function rtrim;
use function sha1;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use function trim;
use function unpack;

/**
 * Exercises the TIFF EXIF reader with synthetic Classic TIFF and BigTIFF payloads.
 */
#[CoversClass(TiffExifReader::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Registry::class)]
final class TiffExifReaderTest extends TestCase
{
    private const int CUSTOM_SIGNED_LONG8_TAG = 0xC7A1;

    private const int BIG_TIFF_INLINE_UNDEFINED_TAG = 0xC7A2;

    private const string BIG_TIFF_INLINE_UNDEFINED_BYTES = "\x01\x00\x00\x00\x00\x00\x00\x80";

    /**
     * Expected StripOffsets values captured from ExifTool parsing the synthetic BigTIFF LONG8 fixture.
     *
     * @var array<int>
     */
    private const array BIG_TIFF_LONG8_STRIP_OFFSETS = [
        0x0000000100000100,
        0x0000000100000200,
    ];

    /**
     * Expected StripByteCounts values captured from ExifTool parsing the synthetic BigTIFF LONG8 fixture.
     *
     * @var array<int>
     */
    private const array BIG_TIFF_LONG8_STRIP_BYTE_COUNTS = [
        0x0000000100000000,
        0x0000000100000080,
    ];

    private const int BIG_TIFF_LONG8_SIGNED = -0x0000000100000000;

    private const int BIG_TIFF_LONG8_JPEG_OFFSET = 0x0000000200000000;

    private const int CLASSIC_TILE_WIDTH = 256;

    private const int CLASSIC_TILE_LENGTH = 128;

    /**
     * @var array<int>
     */
    private const array CLASSIC_TILE_OFFSETS = [8192];

    /**
     * @var array<int>
     */
    private const array CLASSIC_TILE_BYTE_COUNTS = [4096];

    private const int BIG_TIFF_TILE_WIDTH = 1024;

    private const int BIG_TIFF_TILE_LENGTH = 768;

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_TILE_OFFSETS = [
        0x0000000200000100,
        0x0000000200000200,
        0x0000000200000300,
    ];

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_TILE_BYTE_COUNTS = [
        0x0000000100000000,
        0x0000000100000200,
        0x0000000100000400,
    ];

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_OFFSET16_STRIP_OFFSETS = [
        0x0000000000000100,
        0x0000000000000200,
        0x0000000000000300,
    ];

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_OFFSET16_STRIP_BYTE_COUNTS = [
        0x0000000000000080,
        0x0000000000000100,
        0x0000000000000180,
    ];

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_OFFSET16_TILE_OFFSETS = [
        0x0000000000000400,
        0x0000000000000500,
        0x0000000000000600,
    ];

    /**
     * @var array<int>
     */
    private const array BIG_TIFF_OFFSET16_TILE_BYTE_COUNTS = [
        0x0000000000000200,
        0x0000000000000280,
        0x0000000000000300,
    ];

    /**
     * Provides representative Classic TIFF and BigTIFF payloads.
     *
     * @return iterable<string, array{0:string,1:callable(ParsedExif):void}>
     */
    public static function provideValidTiffPayloads(): iterable
    {
        yield 'classic' => [
            self::buildClassicTiffBlob(),
            static function (ParsedExif $doc): void {
                self::assertClassicDocument($doc);
            },
        ];

        yield 'big_tiff' => [
            self::buildBigTiffBlob(),
            static function (ParsedExif $doc): void {
                self::assertBigTiffDocument($doc);
            },
        ];

        yield 'big_tiff_offset16' => [
            self::buildBigTiffOffset16Blob(),
            static function (ParsedExif $doc): void {
                self::assertBigTiffOffset16Document($doc);
            },
        ];
    }

    /**
     * Verifies that valid TIFF payloads are parsed into the expected IFD hierarchy.
     *
     * @param string                     $blob      Binary TIFF/EXIF payload.
     * @param callable(ParsedExif): void $assertion Assertion executed for the parsed document.
     */
    #[Test]
    #[DataProvider('provideValidTiffPayloads')]
    public function parsesValidPayloads(string $blob, callable $assertion): void
    {
        $reader = new TiffExifReader();
        $doc    = $reader->parseFromBlob($blob);

        $assertion($doc);
    }

    /**
     * Ensures the TIFF reader exposes EXIF table 64 accessors via the document and resolver.
     */
    #[Test]
    public function surfacesTable64Accessors(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(self::buildClassicTiffBlob());
        self::assertSame([512], $document->stripOffsets());
        self::assertSame([1024], $document->stripByteCounts());
        self::assertSame(self::CLASSIC_TILE_WIDTH, $document->tileWidth());
        self::assertSame(self::CLASSIC_TILE_LENGTH, $document->tileLength());
        self::assertSame(self::CLASSIC_TILE_OFFSETS, $document->tileOffsets());
        self::assertSame(self::CLASSIC_TILE_BYTE_COUNTS, $document->tileByteCounts());
        self::assertSame([0, 32768, 65535], $document->transferFunction());
        self::assertSame(2048, $document->jpegThumbnailOffset());
        self::assertSame(4096, $document->jpegThumbnailLength());
        self::assertSame([0.0, 255.0, 0.0, 255.0, 0.0, 255.0], $document->referenceBlackWhite());
        self::assertSame('Jane Doe', $document->copyright());
        self::assertSame("Sunrise \u{1F305}", $document->xpTitle());
        self::assertSame("Shot on \u{2615}", $document->xpComment());
        self::assertSame('Åsa K.', $document->xpAuthor());
        self::assertSame(['旅', '海'], $document->xpKeywords());
        self::assertSame("Project \u{2728}", $document->xpSubject());
        self::assertSame("Project \u{2728}", $document->documentName());
        self::assertSame("Shot on \u{2615}", $document->imageDescription());
    }

    /**
     * Ensures DNG colour profile tags propagate through the document and resolver helpers.
     */
    #[Test]
    public function surfacesColorProfileTags(): void
    {
        $blob     = self::buildClassicColorProfileBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('CameraSig v1.0', $document->cameraCalibrationSignature());
        self::assertSame('ProfileSig v2', $document->profileCalibrationSignature());

        $map = $document->profileHueSatMap();
        self::assertNotNull($map);
        self::assertSame([6, 3, 2], $map['dimensions']);
        self::assertSame([0.0, 0.25, 0.5, 0.75], $map['map1']);
        self::assertSame([1.0, 1.25, 1.5], $map['map2']);
        self::assertSame([1.75, 2.0, 2.25], $map['map3']);

        $look = $document->profileLookTable();
        self::assertNotNull($look);
        self::assertSame([3, 2, 2], $look['dimensions']);
        self::assertSame([0.0, 0.5, 1.0, 1.25, 1.5, 1.75], $look['data']);

        $tone = $document->profileToneCurve();
        self::assertNotNull($tone);
        self::assertSame([0.0, 0.0, 0.5, 0.5, 1.0, 1.0], $tone);

        $gain = $document->profileGainTableMap();
        self::assertNotNull($gain);
        self::assertSame([1.0, 1.25, 1.5, 1.75], $gain);
    }

    /**
     * Ensures the DocumentName tag is surfaced even when newer aliases are absent.
     */
    #[Test]
    public function exposesDocumentNameTagWhenAlone(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(self::buildClassicDocumentNameBlob());
        self::assertSame('Scanned Page', $document->documentName());
    }

    #[Test]
    public function resolvesInteropPointerLocatedInIfd0(): void
    {
        $blob     = self::buildClassicInteropPointerInIfd0Blob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('R98', $document->interopIndex());
        self::assertSame('0100', $document->interopVersion());
    }

    #[Test]
    public function resolvesInteropPointerLocatedInIfd1(): void
    {
        $blob     = self::buildClassicInteropPointerInIfd1Blob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('R98', $document->interopIndex());
        self::assertSame('0100', $document->interopVersion());
    }

    #[Test]
    public function resolvesInteropPointerLocatedInSubIfd(): void
    {
        $blob     = self::buildClassicInteropPointerInSubIfdBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('R98', $document->interopIndex());
        self::assertSame('0100', $document->interopVersion());
    }

    #[Test]
    public function resolvesInteropStoredDirectlyInSubIfd(): void
    {
        $blob     = self::buildClassicInteropInlineSubIfdBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('R98', $document->interopIndex());
        self::assertSame('0100', $document->interopVersion());
        self::assertSame(2048, $document->relatedImageWidth());
    }

    #[Test]
    public function skipsInvalidInteropPointerTargets(): void
    {
        $blob     = self::buildClassicInteropPointerWithSubIfdFallbackBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertSame('R98', $document->interopIndex());
        self::assertSame('0100', $document->interopVersion());
    }

    #[Test]
    public function parsesLinkedIfdChain(): void
    {
        $blob     = $this->buildClassicLinkedIfdBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        $subsequentIfds = $document->subsequentIfds();

        self::assertCount(2, $subsequentIfds);
        self::assertSame($document->ifd1, $subsequentIfds[0]);

        $firstIfdEntry = $subsequentIfds[0]->get(ExifTag::IMAGE_HEIGHT);
        self::assertNotNull($firstIfdEntry);
        self::assertSame(200, $firstIfdEntry->value);

        $secondIfdEntry = $subsequentIfds[1]->get(ExifTag::BITS_PER_SAMPLE);
        self::assertNotNull($secondIfdEntry);
        self::assertSame(16, $secondIfdEntry->value);
    }

    /**
     * Ensures printable UNDEFINED payloads are normalised to strings.
     */
    #[Test]
    public function normalisesPrintableUndefinedPayloads(): void
    {
        $blob     = $this->buildClassicVersionBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        $exifIfd = $document->exifIfd;
        self::assertNotNull($exifIfd);

        $exifVersion = $exifIfd->get(ExifTag::EXIF_VERSION)?->value;
        self::assertSame('0232', $exifVersion);

        $flashpixVersion = $exifIfd->get(ExifTag::FLASHPIX_VERSION)?->value;
        self::assertSame('0100', $flashpixVersion);

        self::assertSame('2.32', $document->exifVersion());
        self::assertSame('1.00', $document->flashpixVersion());
    }

    /**
     * Ensures FlashPix version defaults to 1.00 when the tag is absent.
     */
    #[Test]
    public function defaultsFlashpixVersionWhenTagMissing(): void
    {
        $blob     = $this->buildClassicVersionBlobWithoutFlashpix();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        $entry = $document->exifIfd?->get(ExifTag::FLASHPIX_VERSION);
        self::assertNull($entry);
        self::assertSame('1.00', $document->flashpixVersion());
    }

    /**
     * Ensures PrintIM payloads preserve their binary data and are decoded into structured output.
     */
    #[Test]
    public function decodesPrintImageMatchingPayload(): void
    {
        [$blob, $payload] = $this->buildClassicPrintImBlob();

        $document = (new TiffExifReader())->parseFromBlob($blob);
        $exifIfd  = $document->exifIfd;
        self::assertNotNull($exifIfd);

        $entry = $exifIfd->get(ExifTag::PRINT_IMAGE_MATCHING);
        self::assertNotNull($entry);
        self::assertIsString($entry->value);
        self::assertSame($payload, $entry->value);

        $expected = [
            'header'     => 'PrintIM',
            'version'    => '0400',
            'parameters' => [
                ['id' => 0x0100, 'value' => 0x0000002A],
                ['id' => 0x0101, 'value' => 0x00000064],
            ],
        ];

        self::assertSame($expected, $document->printImageMatching());
    }

    /**
     * Ensures malformed PrintIM payloads do not trigger decoding failures.
     */
    #[Test]
    public function ignoresMalformedPrintImageMatchingPayload(): void
    {
        $blob     = $this->buildClassicTruncatedPrintImBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        self::assertNull($document->printImageMatching());
    }

    /**
     * Ensures that TIFF blobs with an unsupported magic identifier are rejected.
     */
    #[Test]
    public function rejectsUnknownMagic(): void
    {
        $blob = 'II' . pack('v', 0x1234) . str_repeat("\0", 4);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unknown TIFF magic');

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures BigTIFF headers with an overflowing first IFD offset are rejected.
     */
    #[Test]
    public function rejectsBigTiffFirstIfdOffsetOverflow(): void
    {
        $blob = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 0x00000000)
            . pack('V', 0x80000000);

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage('IFD offset exceeds TIFF data length.');

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures that invalid pointer offsets trigger bounds checking errors.
     */
    #[Test]
    public function failsOnOutOfRangeIfdPointer(): void
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $entries = self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 4096);
        $ifd0    = pack('v', 1) . $entries . pack('V', 0);

        $blob = $header . $ifd0;

        $this->expectException(BoundsError::class);

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures BigTIFF pointer offsets beyond the signed 63-bit range are rejected with a bounds error.
     */
    #[Test]
    public function failsOnOutOfRangeBigTiffPointer(): void
    {
        $blob = self::buildBigTiffOutOfRangeOffsetBlob();

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x8825 exceeds TIFF data length.');

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures SubIFDs pointers stored using the IFD field type are followed correctly.
     */
    #[Test]
    public function decodesSubIfdPointers(): void
    {
        [$blob, $subIfdOffset] = $this->buildClassicSubIfdBlob();

        $document     = (new TiffExifReader())->parseFromBlob($blob);
        $subIfdsEntry = $document->ifd0->get(ExifTag::SUB_IFDS);

        self::assertNotNull($subIfdsEntry);

        $value = $subIfdsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $value);
        self::assertSame($subIfdOffset, $value->values[0] ?? null);

        $subIfds = $document->subIfds();
        self::assertArrayHasKey($subIfdOffset, $subIfds);

        $nestedIfd = $subIfds[$subIfdOffset];

        $orientation = $nestedIfd->get(ExifTag::ORIENTATION);
        self::assertNotNull($orientation);
        self::assertSame(1, $orientation->value);
    }

    /**
     * Ensures SubIFDs pointers stored using the LONG field type are followed correctly.
     */
    #[Test]
    public function decodesLongTypedSubIfdPointers(): void
    {
        [$blob, $firstSubIfdOffset, $secondSubIfdOffset] = $this->buildClassicLongSubIfdBlob();

        $document     = (new TiffExifReader())->parseFromBlob($blob);
        $subIfdsEntry = $document->ifd0->get(ExifTag::SUB_IFDS);

        self::assertNotNull($subIfdsEntry);
        self::assertInstanceOf(ExifNumericList::class, $subIfdsEntry->value);
        self::assertSame([$firstSubIfdOffset, $secondSubIfdOffset], $subIfdsEntry->value->values);

        $subIfds = $document->subIfds();

        self::assertCount(2, $subIfds);
        self::assertArrayHasKey($firstSubIfdOffset, $subIfds);
        self::assertArrayHasKey($secondSubIfdOffset, $subIfds);

        $firstSubIfdEntry = $subIfds[$firstSubIfdOffset]->get(ExifTag::ORIENTATION);
        self::assertNotNull($firstSubIfdEntry);
        self::assertSame(1, $firstSubIfdEntry->value);

        $secondSubIfdEntry = $subIfds[$secondSubIfdOffset]->get(ExifTag::BITS_PER_SAMPLE);
        self::assertNotNull($secondSubIfdEntry);
        self::assertSame(16, $secondSubIfdEntry->value);
    }

    /**
     * Ensures SubIFD pointers stored inline using the IFD field type remain offsets.
     */
    #[Test]
    public function preservesInlineIfdPointerValues(): void
    {
        [$blob, $subIfdOffset] = $this->buildClassicInlineSubIfdBlob();

        $document    = (new TiffExifReader())->parseFromBlob($blob);
        $subIfdEntry = $document->ifd0->get(ExifTag::SUB_IFDS);

        self::assertNotNull($subIfdEntry);
        self::assertSame($subIfdOffset, $subIfdEntry->value);

        $subIfds = $document->subIfds();
        self::assertArrayHasKey($subIfdOffset, $subIfds);
    }

    /**
     * Ensures BYTE tags with multiple values preserve each byte.
     */
    #[Test]
    public function preservesMultiValueByteTags(): void
    {
        $blob = $this->buildClassicMultiByteTagBlob();

        $document = (new TiffExifReader())->parseFromBlob($blob);

        $entry = $document->ifd0->get(ExifTag::GPS_ALTITUDE_REF);
        self::assertNotNull($entry);
        self::assertInstanceOf(ExifNumericList::class, $entry->value);
        self::assertSame([1, 2, 3], $entry->value->values);
    }

    /**
     * Ensures BYTE tags retain their unsigned interpretation for high-bit values.
     */
    #[Test]
    public function decodesUnsignedByteValues(): void
    {
        $blob = $this->buildClassicHighByteTagBlob();

        $document = (new TiffExifReader())->parseFromBlob($blob);

        $entry = $document->ifd0->get(ExifTag::GPS_ALTITUDE_REF);
        self::assertNotNull($entry);
        self::assertSame(0xFF, $entry->value);
    }

    /**
     * Ensures newly introduced scene and software tags are decoded from Classic TIFF payloads.
     */
    #[Test]
    public function parsesSceneAndSoftwareTags(): void
    {
        $blob = $this->buildClassicSceneSoftwareBlob();

        $document = (new TiffExifReader())->parseFromBlob($blob);
        $exifIfd  = $document->exifIfd;

        self::assertNotNull($exifIfd);

        $sceneTypeEntry = $exifIfd->get(ExifTag::SCENE_TYPE);
        self::assertNotNull($sceneTypeEntry);
        $sceneType = $sceneTypeEntry->value;
        if (is_string($sceneType)) {
            self::assertTrue(
                in_array($sceneType, ["\x01\0\0\0", "\x01"], true),
                'SceneType byte should preserve the original UNDEFINED payload',
            );
        } else {
            self::assertIsInt($sceneType);
            self::assertSame(1, $sceneType);
        }

        $customRenderedEntry = $exifIfd->get(ExifTag::CUSTOM_RENDERED);
        self::assertNotNull($customRenderedEntry);
        self::assertIsInt($customRenderedEntry->value);
        self::assertSame(1, $customRenderedEntry->value);

        $cfaPatternEntry = $exifIfd->get(ExifTag::CFA_PATTERN);
        self::assertNotNull($cfaPatternEntry);
        $cfaPattern = $cfaPatternEntry->value;
        if ($cfaPattern instanceof ExifNumericList) {
            self::assertSame([0, 1, 2, 3], $cfaPattern->values);
        } else {
            self::assertIsString($cfaPattern);
            self::assertSame("\x00\x01\x02\x03", $cfaPattern);
        }

        self::assertSame('Cliffside Dusk', self::trimmedStringValue($exifIfd, ExifTag::IMAGE_TITLE));
        self::assertSame('Alex Light', self::trimmedStringValue($exifIfd, ExifTag::PHOTOGRAPHER));
        self::assertSame('Chris Edit', self::trimmedStringValue($exifIfd, ExifTag::IMAGE_EDITOR));
        self::assertSame('Firmware 2.0', self::trimmedStringValue($exifIfd, ExifTag::CAMERA_FIRMWARE));
        self::assertSame('RawLab Studio', self::trimmedStringValue($exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE));
        self::assertSame('EditLab Pro', self::trimmedStringValue($exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE));
        self::assertSame('MetaLab Suite', self::trimmedStringValue($exifIfd, ExifTag::METADATA_EDITING_SOFTWARE));
        self::assertSame('Ann', self::trimmedStringValue($exifIfd, ExifTag::CAMERA_OWNER_NAME));
    }

    /**
     * Returns a trimmed ASCII value from the provided directory entry.
     */
    private static function trimmedStringValue(Ifd $ifd, int $tag): string
    {
        $entry = $ifd->get($tag);
        self::assertNotNull($entry);
        self::assertIsString($entry->value);

        return rtrim($entry->value);
    }

    /**
     * Ensures truncated byte payloads are converted into parse errors instead of PHP notices.
     */
    #[Test]
    public function rejectsTruncatedBytePayload(): void
    {
        $reader = new TiffExifReader();

        $refClass = new ReflectionClass($reader);
        $boProp   = $refClass->getProperty('bo');
        $boProp->setValue($reader, Endian::Little);

        $decodeBytes = $refClass->getMethod('decodeBytes');

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Truncated value for TIFF type 1');

        $decodeBytes->invoke($reader, 1, 2, "\x01");
    }

    /**
     * Ensures maker note metadata is decoded when a matching decoder is registered.
     */
    #[Test]
    public function decodesMakerNotesWithRegisteredDecoder(): void
    {
        [$blob, $makerNoteData] = $this->buildClassicMakerNoteBlob();

        $decoder = new class implements MakerNotesDecoderInterface {
            public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
            {
                $offset  = unpack('Voffset', substr($raw, 0, 4));
                $pointer = 0;
                if ($offset !== false && isset($offset['offset']) && is_int($offset['offset'])) {
                    $pointer = $offset['offset'];
                }

                $vendor = substr($raw, $pointer, 4);

                return new MakerNotesRecord($vendor !== '' ? $vendor : 'Unknown', strlen($raw), sha1($raw));
            }
        };

        $registry = new Registry();
        $registry->register('Acme', $decoder);

        $document   = (new TiffExifReader())->parseFromBlob($blob, $registry);
        $makerNotes = $document->makerNotes();

        self::assertInstanceOf(MakerNotesRecord::class, $makerNotes);
        self::assertSame('DATA', $makerNotes->vendor);
        self::assertSame(strlen($makerNoteData), $makerNotes->length);
        self::assertSame(sha1($makerNoteData), $makerNotes->sha1);
        self::assertNull($makerNotes->isSafe);
    }

    /**
     * Ensures maker note metadata falls back to a digest when no decoder matches the make string.
     */
    #[Test]
    public function makerNotesFallbackToDigestWithoutMatchingDecoder(): void
    {
        [$blob, $makerNoteData] = $this->buildClassicMakerNoteBlob();

        $registry = new Registry();
        $registry->register('Other', new class implements MakerNotesDecoderInterface {
            public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
            {
                return new MakerNotesRecord('Other', strlen($raw), sha1($raw));
            }
        });

        $document   = (new TiffExifReader())->parseFromBlob($blob, $registry);
        $makerNotes = $document->makerNotes();

        self::assertInstanceOf(MakerNotesRecord::class, $makerNotes);
        self::assertSame('Unknown', $makerNotes->vendor);
        self::assertSame(strlen($makerNoteData), $makerNotes->length);
        self::assertSame(sha1($makerNoteData), $makerNotes->sha1);
        self::assertNull($makerNotes->isSafe);
    }

    /**
     * Ensures the maker note safety flag propagates when the metadata is decoded via a registry.
     */
    #[Test]
    public function propagatesMakerNoteSafetyForRegisteredDecoder(): void
    {
        [$blob] = $this->buildClassicMakerNoteBlob(1);

        $decoder = new class implements MakerNotesDecoderInterface {
            public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
            {
                return new MakerNotesRecord('SafeVendor', strlen($raw), sha1($raw));
            }
        };

        $registry = new Registry();
        $registry->register('AcmeCam', $decoder);

        $document   = (new TiffExifReader())->parseFromBlob($blob, $registry);
        $makerNotes = $document->makerNotes();

        self::assertInstanceOf(MakerNotesRecord::class, $makerNotes);
        self::assertTrue($document->makerNoteSafety());
        self::assertTrue($makerNotes->isSafe);

        self::assertTrue($document->makerNoteSafety());
    }

    /**
     * Ensures the maker note safety flag propagates when falling back to a digest.
     */
    #[Test]
    public function propagatesMakerNoteSafetyForDigestFallback(): void
    {
        [$blob] = $this->buildClassicMakerNoteBlob(0);

        $document   = (new TiffExifReader())->parseFromBlob($blob);
        $makerNotes = $document->makerNotes();

        self::assertInstanceOf(MakerNotesRecord::class, $makerNotes);
        self::assertFalse($document->makerNoteSafety());
        self::assertFalse($makerNotes->isSafe);

        self::assertFalse($document->makerNoteSafety());
    }

    /**
     * Asserts the decoded values of the synthetic Classic TIFF payload.
     *
     * @param ParsedExif $doc Parsed document returned by the TIFF reader.
     */
    private static function assertClassicDocument(ParsedExif $doc): void
    {
        self::assertSame('Canon', $doc->ifd0->get(ExifTag::MAKE)?->value);
        self::assertSame(1, $doc->ifd0->get(ExifTag::ORIENTATION)?->value);
        self::assertSame(512, $doc->ifd0->get(ExifTag::STRIP_OFFSETS)?->value);
        self::assertSame(1024, $doc->ifd0->get(ExifTag::STRIP_BYTE_COUNTS)?->value);
        self::assertSame(self::CLASSIC_TILE_WIDTH, $doc->ifd0->get(ExifTag::TILE_WIDTH)?->value);
        self::assertSame(self::CLASSIC_TILE_LENGTH, $doc->ifd0->get(ExifTag::TILE_LENGTH)?->value);
        self::assertSame(self::CLASSIC_TILE_OFFSETS[0], $doc->ifd0->get(ExifTag::TILE_OFFSETS)?->value);
        self::assertSame(self::CLASSIC_TILE_BYTE_COUNTS[0], $doc->ifd0->get(ExifTag::TILE_BYTE_COUNTS)?->value);
        self::assertSame(2048, $doc->ifd0->get(ExifTag::JPEG_INTERCHANGE_FORMAT)?->value);
        self::assertSame(4096, $doc->ifd0->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH)?->value);

        $transfer = $doc->ifd0->get(ExifTag::TRANSFER_FUNCTION)?->value;
        self::assertInstanceOf(ExifNumericList::class, $transfer);
        self::assertSame([0, 32768, 65535], $transfer->values);

        $refBlackWhite = $doc->ifd0->get(ExifTag::REFERENCE_BLACK_WHITE)?->value;
        self::assertInstanceOf(ExifRationalList::class, $refBlackWhite);
        self::assertSame(
            [[0, 1], [255, 1], [0, 1], [255, 1], [0, 1], [255, 1]],
            array_map(static fn (ExifRational $r): array => [$r->numerator, $r->denominator], $refBlackWhite->values),
        );

        $copyright = $doc->ifd0->get(ExifTag::COPYRIGHT)?->value;
        self::assertIsString($copyright);
        self::assertSame('Jane Doe', trim($copyright, "\0"));

        self::assertSame(self::CLASSIC_TILE_WIDTH, $doc->tileWidth());
        self::assertSame(self::CLASSIC_TILE_LENGTH, $doc->tileLength());
        self::assertSame(self::CLASSIC_TILE_OFFSETS, $doc->tileOffsets());
        self::assertSame(self::CLASSIC_TILE_BYTE_COUNTS, $doc->tileByteCounts());

        $exifIfd = $doc->exifIfd;
        self::assertNotNull($exifIfd);
        self::assertSame(200, $exifIfd->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)?->value);
        $fNumber = $exifIfd->get(ExifTag::F_NUMBER)?->value;
        self::assertInstanceOf(ExifRational::class, $fNumber);
        self::assertSame(28, $fNumber->numerator);
        self::assertSame(10, $fNumber->denominator);

        $interop = $doc->interopIfd;
        self::assertNotNull($interop);
        self::assertSame('R98', $interop->get(0x0001)?->value);
        self::assertNull($interop->get(0x0002));

        $gpsIfd = $doc->gpsIfd;
        self::assertNotNull($gpsIfd);
        $lat = $gpsIfd->get(ExifTag::GPS_LATITUDE)?->value;
        self::assertInstanceOf(ExifRationalList::class, $lat);
        self::assertSame(
            [[40, 1], [30, 1], [15, 1]],
            array_map(static fn (ExifRational $r): array => [$r->numerator, $r->denominator], $lat->values),
        );

        $lon = $gpsIfd->get(ExifTag::GPS_LONGITUDE)?->value;
        self::assertInstanceOf(ExifRationalList::class, $lon);
        self::assertSame(
            [[70, 1], [45, 1], [30, 1]],
            array_map(static fn (ExifRational $r): array => [$r->numerator, $r->denominator], $lon->values),
        );

        $alt = $gpsIfd->get(ExifTag::GPS_ALTITUDE)?->value;
        self::assertInstanceOf(ExifRational::class, $alt);
        self::assertSame(150, $alt->numerator);
        self::assertSame(1, $alt->denominator);

        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.504166, $gps['lat'], 1e-6);
        self::assertEqualsWithDelta(70.758333, $gps['lon'], 1e-6);
        self::assertEqualsWithDelta(-150.0, $gps['alt'], 1e-3);
    }

    /**
     * Asserts the decoded values of the synthetic BigTIFF payload.
     *
     * @param ParsedExif $doc Parsed document returned by the TIFF reader.
     */
    private static function assertBigTiffDocument(ParsedExif $doc): void
    {
        self::assertSame('BigCamXL', $doc->ifd0->get(ExifTag::MAKE)?->value);
        self::assertSame(3, $doc->ifd0->get(ExifTag::ORIENTATION)?->value);
        self::assertSame(self::BIG_TIFF_TILE_WIDTH, $doc->ifd0->get(ExifTag::TILE_WIDTH)?->value);
        self::assertSame(self::BIG_TIFF_TILE_LENGTH, $doc->ifd0->get(ExifTag::TILE_LENGTH)?->value);

        $tileOffsetsEntry = $doc->ifd0->get(ExifTag::TILE_OFFSETS);
        self::assertNotNull($tileOffsetsEntry);
        $tileOffsets = $tileOffsetsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $tileOffsets);
        self::assertSame(self::BIG_TIFF_TILE_OFFSETS, $tileOffsets->values);

        $tileByteCountsEntry = $doc->ifd0->get(ExifTag::TILE_BYTE_COUNTS);
        self::assertNotNull($tileByteCountsEntry);
        $tileByteCounts = $tileByteCountsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $tileByteCounts);
        self::assertSame(self::BIG_TIFF_TILE_BYTE_COUNTS, $tileByteCounts->values);

        $exifIfd = $doc->exifIfd;
        self::assertNotNull($exifIfd);
        self::assertSame(320, $exifIfd->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)?->value);
        $focalLength = $exifIfd->get(ExifTag::FOCAL_LENGTH)?->value;
        self::assertInstanceOf(ExifRational::class, $focalLength);
        self::assertSame(35, $focalLength->numerator);
        self::assertSame(10, $focalLength->denominator);

        $interop = $doc->interopIfd;
        self::assertNotNull($interop);
        self::assertSame('R98', $interop->get(0x0001)?->value);
        self::assertNull($interop->get(0x0002));

        $gpsIfd = $doc->gpsIfd;
        self::assertNotNull($gpsIfd);
        $lat = $gpsIfd->get(ExifTag::GPS_LATITUDE)?->value;
        self::assertInstanceOf(ExifRationalList::class, $lat);
        self::assertSame(
            [[51, 1], [30, 1], [15, 1]],
            array_map(static fn (ExifRational $r): array => [$r->numerator, $r->denominator], $lat->values),
        );

        $lon = $gpsIfd->get(ExifTag::GPS_LONGITUDE)?->value;
        self::assertInstanceOf(ExifRationalList::class, $lon);
        self::assertSame(
            [[8, 1], [12, 1], [30, 1]],
            array_map(static fn (ExifRational $r): array => [$r->numerator, $r->denominator], $lon->values),
        );

        $alt = $gpsIfd->get(ExifTag::GPS_ALTITUDE)?->value;
        self::assertInstanceOf(ExifRational::class, $alt);
        self::assertSame(500, $alt->numerator);
        self::assertSame(10, $alt->denominator);

        $gps = $doc->gps();
        self::assertEqualsWithDelta(51.504167, $gps['lat'], 1e-6);
        self::assertEqualsWithDelta(-8.208333, $gps['lon'], 1e-6);
        self::assertEqualsWithDelta(-50.0, $gps['alt'], 1e-3);

        self::assertSame(self::BIG_TIFF_TILE_WIDTH, $doc->tileWidth());
        self::assertSame(self::BIG_TIFF_TILE_LENGTH, $doc->tileLength());
        self::assertSame(self::BIG_TIFF_TILE_OFFSETS, $doc->tileOffsets());
        self::assertSame(self::BIG_TIFF_TILE_BYTE_COUNTS, $doc->tileByteCounts());
    }

    /**
     * Asserts the decoded values of the synthetic 16-byte BigTIFF payload.
     */
    private static function assertBigTiffOffset16Document(ParsedExif $doc): void
    {
        $stripOffsetsEntry = $doc->ifd0->get(ExifTag::STRIP_OFFSETS);
        self::assertNotNull($stripOffsetsEntry);
        $stripOffsets = $stripOffsetsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $stripOffsets);
        self::assertSame(self::BIG_TIFF_OFFSET16_STRIP_OFFSETS, $stripOffsets->values);

        $stripCountsEntry = $doc->ifd0->get(ExifTag::STRIP_BYTE_COUNTS);
        self::assertNotNull($stripCountsEntry);
        $stripByteCounts = $stripCountsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $stripByteCounts);
        self::assertSame(self::BIG_TIFF_OFFSET16_STRIP_BYTE_COUNTS, $stripByteCounts->values);

        $tileOffsetsEntry = $doc->ifd0->get(ExifTag::TILE_OFFSETS);
        self::assertNotNull($tileOffsetsEntry);
        $tileOffsets = $tileOffsetsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $tileOffsets);
        self::assertSame(self::BIG_TIFF_OFFSET16_TILE_OFFSETS, $tileOffsets->values);

        $tileCountsEntry = $doc->ifd0->get(ExifTag::TILE_BYTE_COUNTS);
        self::assertNotNull($tileCountsEntry);
        $tileByteCounts = $tileCountsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $tileByteCounts);
        self::assertSame(self::BIG_TIFF_OFFSET16_TILE_BYTE_COUNTS, $tileByteCounts->values);

        self::assertSame(self::BIG_TIFF_OFFSET16_STRIP_OFFSETS, $doc->stripOffsets());
        self::assertSame(self::BIG_TIFF_OFFSET16_STRIP_BYTE_COUNTS, $doc->stripByteCounts());
        self::assertSame(self::BIG_TIFF_OFFSET16_TILE_OFFSETS, $doc->tileOffsets());
        self::assertSame(self::BIG_TIFF_OFFSET16_TILE_BYTE_COUNTS, $doc->tileByteCounts());
    }

    /**
     * Ensures BigTIFF LONG8/SLONG8/IFD8 entries are decoded exactly like ExifTool.
     */
    #[Test]
    public function parsesBigTiffLong8FieldTypes(): void
    {
        $reader   = new TiffExifReader();
        $document = $reader->parseFromBlob(self::buildBigTiffLong8OffsetsBlob());

        $ifd0 = $document->ifd0;

        $stripOffsetsEntry = $ifd0->get(ExifTag::STRIP_OFFSETS);
        self::assertNotNull($stripOffsetsEntry);
        $stripOffsets = $stripOffsetsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $stripOffsets);
        self::assertSame(self::BIG_TIFF_LONG8_STRIP_OFFSETS, $stripOffsets->values);

        $stripByteCountsEntry = $ifd0->get(ExifTag::STRIP_BYTE_COUNTS);
        self::assertNotNull($stripByteCountsEntry);
        $stripByteCounts = $stripByteCountsEntry->value;
        self::assertInstanceOf(ExifNumericList::class, $stripByteCounts);
        self::assertSame(self::BIG_TIFF_LONG8_STRIP_BYTE_COUNTS, $stripByteCounts->values);

        $signedEntry = $ifd0->get(self::CUSTOM_SIGNED_LONG8_TAG);
        self::assertNotNull($signedEntry);
        self::assertSame(self::BIG_TIFF_LONG8_SIGNED, $signedEntry->value);

        $jpegEntry = $ifd0->get(ExifTag::JPEG_INTERCHANGE_FORMAT);
        self::assertNotNull($jpegEntry);
        self::assertSame(self::BIG_TIFF_LONG8_JPEG_OFFSET, $jpegEntry->value);

        $refClass      = new ReflectionClass($reader);
        $pointerMethod = $refClass->getMethod('pointerOffset');

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x0201 exceeds TIFF data length.');

        $pointerMethod->invoke($reader, $jpegEntry);
    }

    #[Test]
    public function preservesBigTiffInlineUndefinedHighBit(): void
    {
        $reader   = new TiffExifReader();
        $document = $reader->parseFromBlob(self::buildBigTiffInlineUndefinedHighBitBlob());

        $entry = $document->ifd0->get(self::BIG_TIFF_INLINE_UNDEFINED_TAG);
        self::assertNotNull($entry);

        $value = $entry->value;
        self::assertIsString($value);
        self::assertSame(self::BIG_TIFF_INLINE_UNDEFINED_BYTES, $value);
        self::assertSame(0x80, ord($value[7]));
    }

    #[Test]
    public function decodesBigTiffInlineNegativeDouble(): void
    {
        $reader   = new TiffExifReader();
        $document = $reader->parseFromBlob(self::buildBigTiffInlineNegativeDoubleBlob());

        $entry = $document->ifd0->get(ExifTag::CAMERA_YAW_DEGREE);
        self::assertNotNull($entry);

        $value = $entry->value;
        self::assertIsFloat($value);
        self::assertSame(-5.0, $value);
    }

    /**
     * Builds a Classic TIFF little-endian EXIF payload with nested IFDs.
     */
    private static function buildClassicTiffBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $makeString            = "Canon\0";
        $transferFunctionBytes = pack('v*', 0, 32768, 65535);
        $referenceBlackWhite   = self::packRationalLE(0, 1)
            . self::packRationalLE(255, 1)
            . self::packRationalLE(0, 1)
            . self::packRationalLE(255, 1)
            . self::packRationalLE(0, 1)
            . self::packRationalLE(255, 1);
        $copyrightString = "Jane Doe\0";
        $xpTitleBytes    = self::packUtf16LeString([0x0053, 0x0075, 0x006E, 0x0072, 0x0069, 0x0073, 0x0065, 0x0020, 0x1F305]);
        $xpCommentBytes  = self::packUtf16LeString([0x0053, 0x0068, 0x006F, 0x0074, 0x0020, 0x006F, 0x006E, 0x0020, 0x2615]);
        $xpAuthorBytes   = self::packUtf16LeString([0x00C5, 0x0073, 0x0061, 0x0020, 0x004B, 0x002E]);
        $xpKeywordsBytes = self::packUtf16LeString([0x65C5, 0x003B, 0x6D77]);
        $xpSubjectBytes  = self::packUtf16LeString([0x0050, 0x0072, 0x006F, 0x006A, 0x0065, 0x0063, 0x0074, 0x0020, 0x2728]);

        $ifd0EntryCount = 20;
        $ifd0Length     = 2 + ($ifd0EntryCount * 12) + 4;
        $baseOffset     = strlen($header) + $ifd0Length;

        $makeOffset                = $baseOffset;
        $transferFunctionOffset    = $makeOffset + strlen($makeString);
        $referenceBlackWhiteOffset = $transferFunctionOffset + strlen($transferFunctionBytes);
        $copyrightOffset           = $referenceBlackWhiteOffset + strlen($referenceBlackWhite);
        $xpTitleOffset             = $copyrightOffset + strlen($copyrightString);
        $xpCommentOffset           = $xpTitleOffset + strlen($xpTitleBytes);
        $xpAuthorOffset            = $xpCommentOffset + strlen($xpCommentBytes);
        $xpKeywordsOffset          = $xpAuthorOffset + strlen($xpAuthorBytes);
        $xpSubjectOffset           = $xpKeywordsOffset + strlen($xpKeywordsBytes);
        $exifIfdOffset             = $xpSubjectOffset + strlen($xpSubjectBytes);

        $exifEntryCount   = 3;
        $exifIfdLength    = 2 + ($exifEntryCount * 12) + 4;
        $fNumberData      = self::packRationalLE(28, 10);
        $fNumberOffset    = $exifIfdOffset + $exifIfdLength;
        $interopIfdOffset = $fNumberOffset + strlen($fNumberData);
        $interopIfdLength = 2 + 12 + 4;
        $gpsIfdOffset     = $interopIfdOffset + $interopIfdLength;

        $gpsEntryCount = 6;
        $gpsIfdLength  = 2 + ($gpsEntryCount * 12) + 4;
        $gpsLatData    = self::packRationalTripletLE([40, 1], [30, 1], [15, 1]);
        $gpsLonData    = self::packRationalTripletLE([70, 1], [45, 1], [30, 1]);
        $gpsAltData    = self::packRationalLE(150, 1);

        $gpsLatOffset = $gpsIfdOffset + $gpsIfdLength;
        $gpsLonOffset = $gpsLatOffset + strlen($gpsLatData);
        $gpsAltOffset = $gpsLonOffset + strlen($gpsLonData);

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::MAKE, 2, strlen($makeString), $makeOffset),
            self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1),
            self::packClassicEntry(ExifTag::STRIP_OFFSETS, 4, 1, 512),
            self::packClassicEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 1, 1024),
            self::packClassicEntry(ExifTag::TILE_WIDTH, 4, 1, self::CLASSIC_TILE_WIDTH),
            self::packClassicEntry(ExifTag::TILE_LENGTH, 4, 1, self::CLASSIC_TILE_LENGTH),
            self::packClassicEntry(ExifTag::TILE_OFFSETS, 4, 1, self::CLASSIC_TILE_OFFSETS[0]),
            self::packClassicEntry(ExifTag::TILE_BYTE_COUNTS, 4, 1, self::CLASSIC_TILE_BYTE_COUNTS[0]),
            self::packClassicEntry(ExifTag::TRANSFER_FUNCTION, 3, 3, $transferFunctionOffset),
            self::packClassicEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 2048),
            self::packClassicEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 4096),
            self::packClassicEntry(ExifTag::REFERENCE_BLACK_WHITE, 5, 6, $referenceBlackWhiteOffset),
            self::packClassicEntry(ExifTag::COPYRIGHT, 2, strlen($copyrightString), $copyrightOffset),
            self::packClassicEntry(ExifTag::XP_TITLE, 1, strlen($xpTitleBytes), $xpTitleOffset),
            self::packClassicEntry(ExifTag::XP_COMMENT, 1, strlen($xpCommentBytes), $xpCommentOffset),
            self::packClassicEntry(ExifTag::XP_AUTHOR, 1, strlen($xpAuthorBytes), $xpAuthorOffset),
            self::packClassicEntry(ExifTag::XP_KEYWORDS, 1, strlen($xpKeywordsBytes), $xpKeywordsOffset),
            self::packClassicEntry(ExifTag::XP_SUBJECT, 1, strlen($xpSubjectBytes), $xpSubjectOffset),
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
            self::packClassicEntry(ExifTag::GPS_IFD_POINTER, 4, 1, $gpsIfdOffset),
        ];
        $ifd0 = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);

        $blob = $header . $ifd0;
        $blob .= $makeString;
        $blob .= $transferFunctionBytes;
        $blob .= $referenceBlackWhite;
        $blob .= $copyrightString;
        $blob .= $xpTitleBytes;
        $blob .= $xpCommentBytes;
        $blob .= $xpAuthorBytes;
        $blob .= $xpKeywordsBytes;
        $blob .= $xpSubjectBytes;

        $exifEntries = [
            self::packClassicEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            self::packClassicEntry(ExifTag::F_NUMBER, 5, 1, $fNumberOffset),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $interopIfdOffset),
        ];
        $blob .= pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);
        $blob .= $fNumberData;

        $interopEntries = [
            self::packClassicEntry(0x0001, 2, 4, self::inlineAsciiToInt('R98', 4)),
        ];
        $blob .= pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        $gpsEntries = [
            self::packClassicEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, self::inlineAsciiToInt('N', 4)),
            self::packClassicEntry(ExifTag::GPS_LATITUDE, 5, 3, $gpsLatOffset),
            self::packClassicEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, self::inlineAsciiToInt('E', 4)),
            self::packClassicEntry(ExifTag::GPS_LONGITUDE, 5, 3, $gpsLonOffset),
            self::packClassicEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            self::packClassicEntry(ExifTag::GPS_ALTITUDE, 5, 1, $gpsAltOffset),
        ];
        $blob .= pack('v', count($gpsEntries)) . implode('', $gpsEntries) . pack('V', 0);

        $blob .= $gpsLatData;
        $blob .= $gpsLonData;
        $blob .= $gpsAltData;

        return $blob;
    }

    /**
     * Builds a Classic TIFF blob containing DNG colour profile metadata in a SubIFD.
     */
    private static function buildClassicColorProfileBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $cameraSignature  = "CameraSig v1.0\0";
        $profileSignature = "ProfileSig v2\0";

        $ifd0EntryCount = 2;
        $ifd0Length     = 2 + ($ifd0EntryCount * 12) + 4;
        $exifIfdOffset  = 8 + $ifd0Length;

        $exifEntryCount = 2;
        $exifIfdLength  = 2 + ($exifEntryCount * 12) + 4;

        $cameraSignatureOffset  = $exifIfdOffset + $exifIfdLength;
        $profileSignatureOffset = $cameraSignatureOffset + strlen($cameraSignature);
        $subIfdOffset           = self::alignOffset($profileSignatureOffset + strlen($profileSignature), 2);

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
            self::packClassicEntry(ExifTag::SUB_IFDS, 4, 1, $subIfdOffset),
        ];

        $blob = $header;
        $blob .= pack('v', $ifd0EntryCount) . implode('', $ifd0Entries) . pack('V', 0);

        $exifEntries = [
            self::packClassicEntry(ExifTag::CAMERA_CALIBRATION_SIGNATURE, 2, strlen($cameraSignature), $cameraSignatureOffset),
            self::packClassicEntry(ExifTag::PROFILE_CALIBRATION_SIGNATURE, 2, strlen($profileSignature), $profileSignatureOffset),
        ];
        $blob .= pack('v', $exifEntryCount) . implode('', $exifEntries) . pack('V', 0);
        $blob .= $cameraSignature;
        $blob .= $profileSignature;

        self::padBufferTo($blob, $subIfdOffset);

        $hueSatDims = [6, 3, 2];
        $hueSatMap1 = [0.0, 0.25, 0.5, 0.75];
        $hueSatMap2 = [1.0, 1.25, 1.5];
        $hueSatMap3 = [1.75, 2.0, 2.25];
        $lookDims   = [3, 2, 2];
        $lookData   = [0.0, 0.5, 1.0, 1.25, 1.5, 1.75];
        $toneCurve  = [0.0, 0.0, 0.5, 0.5, 1.0, 1.0];
        $gainMap    = [1.0, 1.25, 1.5, 1.75];

        $dimsBytes      = pack('v*', ...$hueSatDims);
        $map1Bytes      = pack('g*', ...$hueSatMap1);
        $map2Bytes      = pack('g*', ...$hueSatMap2);
        $map3Bytes      = pack('g*', ...$hueSatMap3);
        $lookDimsBytes  = pack('v*', ...$lookDims);
        $lookDataBytes  = pack('g*', ...$lookData);
        $toneCurveBytes = pack('g*', ...$toneCurve);
        $gainMapBytes   = pack('g*', ...$gainMap);

        $subIfdEntryCount = 8;
        $subIfdLength     = 2 + ($subIfdEntryCount * 12) + 4;
        $dataOffset       = $subIfdOffset + $subIfdLength;

        $dimsOffset      = $dataOffset;
        $map1Offset      = $dimsOffset + strlen($dimsBytes);
        $map2Offset      = $map1Offset + strlen($map1Bytes);
        $map3Offset      = $map2Offset + strlen($map2Bytes);
        $lookDimsOffset  = $map3Offset + strlen($map3Bytes);
        $lookDataOffset  = $lookDimsOffset + strlen($lookDimsBytes);
        $toneCurveOffset = $lookDataOffset + strlen($lookDataBytes);
        $gainMapOffset   = $toneCurveOffset + strlen($toneCurveBytes);

        $subIfdEntries = [
            self::packClassicEntry(ExifTag::PROFILE_HUE_SAT_MAP_DIMS, 3, count($hueSatDims), $dimsOffset),
            self::packClassicEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_1, 11, count($hueSatMap1), $map1Offset),
            self::packClassicEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_2, 11, count($hueSatMap2), $map2Offset),
            self::packClassicEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_3, 11, count($hueSatMap3), $map3Offset),
            self::packClassicEntry(ExifTag::PROFILE_LOOK_TABLE_DIMS, 3, count($lookDims), $lookDimsOffset),
            self::packClassicEntry(ExifTag::PROFILE_LOOK_TABLE_DATA, 11, count($lookData), $lookDataOffset),
            self::packClassicEntry(ExifTag::PROFILE_TONE_CURVE, 11, count($toneCurve), $toneCurveOffset),
            self::packClassicEntry(DngProfileGainTableTag::GAIN_TABLE_MAP->value, 11, count($gainMap), $gainMapOffset),
        ];

        $blob .= pack('v', $subIfdEntryCount) . implode('', $subIfdEntries) . pack('V', 0);
        $blob .= $dimsBytes;
        $blob .= $map1Bytes;
        $blob .= $map2Bytes;
        $blob .= $map3Bytes;
        $blob .= $lookDimsBytes;
        $blob .= $lookDataBytes;
        $blob .= $toneCurveBytes;
        $blob .= $gainMapBytes;

        return $blob;
    }

    /**
     * Builds a Classic TIFF blob with the interoperability pointer stored in IFD0.
     */
    private static function buildClassicInteropPointerInIfd0Blob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifdEntryCount = 1;
        $ifdLength     = 2 + 12 + 4;
        $interopOffset = 8 + $ifdLength;

        $ifd0 = pack('v', $ifdEntryCount)
            . self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $interopOffset)
            . pack('V', 0);

        $interopEntries = [
            self::packClassicEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, self::inlineAsciiToInt('R98', 4)),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_VERSION, 2, 4, self::inlineAsciiToInt('0100', 4)),
        ];

        $interopIfd = pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        return $header . $ifd0 . $interopIfd;
    }

    /**
     * Builds a Classic TIFF blob with the interoperability pointer in the second IFD.
     */
    private static function buildClassicInteropPointerInIfd1Blob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0Length    = 2 + 4;
        $ifd1Offset    = 8 + $ifd0Length;
        $ifd1Length    = 2 + 12 + 4;
        $interopOffset = $ifd1Offset + $ifd1Length;

        $ifd0 = pack('v', 0)
            . pack('V', $ifd1Offset);

        $ifd1 = pack('v', 1)
            . self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $interopOffset)
            . pack('V', 0);

        $interopEntries = [
            self::packClassicEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, self::inlineAsciiToInt('R98', 4)),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_VERSION, 2, 4, self::inlineAsciiToInt('0100', 4)),
        ];

        $interopIfd = pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        return $header . $ifd0 . $ifd1 . $interopIfd;
    }

    /**
     * Builds a Classic TIFF blob with the interoperability pointer referenced from a SubIFD.
     */
    private static function buildClassicInteropPointerInSubIfdBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0Length    = 2 + 12 + 4;
        $subIfdOffset  = 8 + $ifd0Length;
        $subIfdLength  = 2 + 12 + 4;
        $interopOffset = $subIfdOffset + $subIfdLength;

        $ifd0 = pack('v', 1)
            . self::packClassicEntry(ExifTag::SUB_IFDS, 4, 1, $subIfdOffset)
            . pack('V', 0);

        $subIfd = pack('v', 1)
            . self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $interopOffset)
            . pack('V', 0);

        $interopEntries = [
            self::packClassicEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, self::inlineAsciiToInt('R98', 4)),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_VERSION, 2, 4, self::inlineAsciiToInt('0100', 4)),
        ];

        $interopIfd = pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        return $header . $ifd0 . $subIfd . $interopIfd;
    }

    private static function buildClassicInteropInlineSubIfdBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0Length   = 2 + 12 + 4;
        $subIfdOffset = 8 + $ifd0Length;

        $ifd0 = pack('v', 1)
            . self::packClassicEntry(ExifTag::SUB_IFDS, 4, 1, $subIfdOffset)
            . pack('V', 0);

        $subIfdEntries = [
            self::packClassicEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, self::inlineAsciiToInt('R98', 4)),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_VERSION, 2, 4, self::inlineAsciiToInt('0100', 4)),
            self::packClassicEntry(ExifTag::RELATED_IMAGE_WIDTH, 4, 1, 2048),
        ];

        $subIfd = pack('v', count($subIfdEntries))
            . implode('', $subIfdEntries)
            . pack('V', 0);

        return $header . $ifd0 . $subIfd;
    }

    /**
     * Builds a Classic TIFF blob where the EXIF pointer is stale but the SubIFD provides interoperability tags.
     */
    private static function buildClassicInteropPointerWithSubIfdFallbackBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0Length    = 2 + (2 * 12) + 4;
        $exifOffset    = 8 + $ifd0Length;
        $exifLength    = 2 + 12 + 4;
        $invalidOffset = $exifOffset + $exifLength;
        $invalidLength = 2 + 12 + 4;
        $subIfdOffset  = $invalidOffset + $invalidLength;

        $ifd0 = pack('v', 2)
            . self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifOffset)
            . self::packClassicEntry(ExifTag::SUB_IFDS, 4, 1, $subIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', 1)
            . self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $invalidOffset)
            . pack('V', 0);

        $invalidIfd = pack('v', 1)
            . self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1)
            . pack('V', 0);

        $interopEntries = [
            self::packClassicEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, self::inlineAsciiToInt('R98', 4)),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_VERSION, 2, 4, self::inlineAsciiToInt('0100', 4)),
        ];

        $subIfd = pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd . $invalidIfd . $subIfd;
    }

    /**
     * Builds a Classic TIFF blob that only exposes the legacy DocumentName tag.
     */
    private static function buildClassicDocumentNameBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $documentName       = "Scanned Page\0\0";
        $documentNameOffset = strlen($header) + 2 + 12 + 4;

        $entries = [
            self::packClassicEntry(ExifTag::DOCUMENT_NAME, 2, strlen($documentName), $documentNameOffset),
        ];

        $ifd0 = pack('v', count($entries)) . implode('', $entries) . pack('V', 0);

        return $header . $ifd0 . $documentName;
    }

    /**
     * Builds a Classic TIFF blob that chains multiple subsequent IFDs.
     */
    private function buildClassicLinkedIfdBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifdEntryCount = 1;
        $ifdLength     = 2 + 12 + 4;
        $ifd1Offset    = 8 + $ifdLength;
        $ifd2Offset    = $ifd1Offset + $ifdLength;

        $ifd0 = pack('v', $ifdEntryCount)
            . self::packClassicEntry(ExifTag::IMAGE_WIDTH, 3, 1, 100)
            . pack('V', $ifd1Offset);

        $ifd1 = pack('v', $ifdEntryCount)
            . self::packClassicEntry(ExifTag::IMAGE_HEIGHT, 3, 1, 200)
            . pack('V', $ifd2Offset);

        $ifd2 = pack('v', $ifdEntryCount)
            . self::packClassicEntry(ExifTag::BITS_PER_SAMPLE, 3, 1, 16)
            . pack('V', 0);

        return $header . $ifd0 . $ifd1 . $ifd2;
    }

    /**
     * Builds a Classic TIFF blob with an EXIF version entry but no FlashPix version tag.
     */
    private function buildClassicVersionBlobWithoutFlashpix(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $exifIfdOffset = 8 + 2 + 12 + 4;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
        ];
        $ifd0 = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);

        $exifEntries = [
            self::packClassicEntry(ExifTag::EXIF_VERSION, 7, 4, self::inlineAsciiToInt('0232', 4)),
        ];

        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd;
    }

    /**
     * Builds a Classic TIFF blob with EXIF/Flashpix version tags encoded as printable UNDEFINED values.
     */
    private function buildClassicVersionBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $exifIfdOffset = 8 + 2 + 12 + 4;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
        ];
        $ifd0 = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);

        $exifEntries = [
            self::packClassicEntry(ExifTag::EXIF_VERSION, 7, 4, self::inlineAsciiToInt('0232', 4)),
            self::packClassicEntry(ExifTag::FLASHPIX_VERSION, 7, 4, self::inlineAsciiToInt('0100', 4)),
        ];

        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd;
    }

    /**
     * Builds a Classic TIFF blob containing a PrintIM payload.
     *
     * @return array{0: string, 1: string}
     */
    private function buildClassicPrintImBlob(): array
    {
        $payload = $this->buildPrintImPayload();

        return [$this->buildClassicPrintImBlobFromPayload($payload), $payload];
    }

    /**
     * Builds a Classic TIFF blob containing a truncated PrintIM payload.
     */
    private function buildClassicTruncatedPrintImBlob(): string
    {
        $payload   = $this->buildPrintImPayload();
        $truncated = substr($payload, 0, -1);

        return $this->buildClassicPrintImBlobFromPayload($truncated);
    }

    /**
     * Packs a Classic TIFF blob referencing the provided PrintIM payload.
     */
    private function buildClassicPrintImBlobFromPayload(string $payload): string
    {
        $header        = 'II' . pack('v', 0x002A) . pack('V', 8);
        $ifd0Size      = 2 + 12 + 4;
        $exifIfdOffset = 8 + $ifd0Size;
        $exifIfdSize   = 2 + 12 + 4;
        $printImOffset = $exifIfdOffset + $exifIfdSize;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
        ];

        $exifEntries = [
            self::packClassicEntry(ExifTag::PRINT_IMAGE_MATCHING, 7, strlen($payload), $printImOffset),
        ];

        $ifd0    = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);
        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd . $payload;
    }

    /**
     * Builds a synthetic PrintIM payload with two entries for testing.
     */
    private function buildPrintImPayload(): string
    {
        $parameters = [
            [0x0100, 0x0000002A],
            [0x0101, 0x00000064],
        ];

        $payload = 'PrintIM 0400' . pack('n', count($parameters));

        foreach ($parameters as [$id, $value]) {
            $payload .= pack('nN', $id, $value);
        }

        return $payload;
    }

    /**
     * Builds a Classic TIFF blob containing a SubIFDs tag that references an external IFD.
     *
     * @return array{0: string, 1: int}
     */
    private function buildClassicSubIfdBlob(): array
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0EntryCount     = 1;
        $pointerCount       = 2;
        $ifd0Size           = 2 + 12 + 4;
        $pointerArrayOffset = 8 + $ifd0Size;
        $subIfdOffset       = 64;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::SUB_IFDS, 13, $pointerCount, $pointerArrayOffset),
        ];

        $blob = $header;
        $blob .= pack('v', $ifd0EntryCount) . implode('', $ifd0Entries) . pack('V', 0);
        $blob .= pack('V', $subIfdOffset);
        $blob .= pack('V', 0);

        $blob = str_pad($blob, $subIfdOffset, "\0", STR_PAD_RIGHT);

        $subIfdEntries = [
            self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1),
        ];
        $blob .= pack('v', count($subIfdEntries)) . implode('', $subIfdEntries) . pack('V', 0);

        return [$blob, $subIfdOffset];
    }

    /**
     * Builds a Classic TIFF blob whose SubIFDs tag stores offsets using the LONG type.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function buildClassicLongSubIfdBlob(): array
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0EntryCount     = 1;
        $pointerCount       = 2;
        $ifd0Size           = 2 + 12 + 4;
        $pointerArrayOffset = 8 + $ifd0Size;
        $firstSubIfdOffset  = 96;
        $secondSubIfdOffset = 160;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::SUB_IFDS, 4, $pointerCount, $pointerArrayOffset),
        ];

        $blob = $header;
        $blob .= pack('v', $ifd0EntryCount) . implode('', $ifd0Entries) . pack('V', 0);
        $blob .= pack('V', $firstSubIfdOffset);
        $blob .= pack('V', $secondSubIfdOffset);

        $blob = str_pad($blob, $firstSubIfdOffset, "\0", STR_PAD_RIGHT);

        $firstSubIfdEntries = [
            self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1),
        ];
        $blob .= pack('v', count($firstSubIfdEntries)) . implode('', $firstSubIfdEntries) . pack('V', 0);

        $blob = str_pad($blob, $secondSubIfdOffset, "\0", STR_PAD_RIGHT);

        $secondSubIfdEntries = [
            self::packClassicEntry(ExifTag::BITS_PER_SAMPLE, 3, 1, 16),
        ];
        $blob .= pack('v', count($secondSubIfdEntries)) . implode('', $secondSubIfdEntries) . pack('V', 0);

        return [$blob, $firstSubIfdOffset, $secondSubIfdOffset];
    }

    /**
     * Builds a Classic TIFF blob whose SubIFDs tag stores the pointer inline.
     *
     * @return array{0: string, 1: int}
     */
    private function buildClassicInlineSubIfdBlob(): array
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $subIfdOffset = 64;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::SUB_IFDS, 13, 1, $subIfdOffset),
        ];

        $blob = $header;
        $blob .= pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);
        $blob = str_pad($blob, $subIfdOffset, "\0", STR_PAD_RIGHT);

        $subIfdEntries = [
            self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1),
        ];
        $blob .= pack('v', count($subIfdEntries)) . implode('', $subIfdEntries) . pack('V', 0);

        return [$blob, $subIfdOffset];
    }

    /**
     * Builds a Classic TIFF blob containing a multi-value BYTE tag.
     */
    private function buildClassicMultiByteTagBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $inlineValue = $this->inlineBytes([1, 2, 3], 4);
        $entry       = self::packClassicEntry(ExifTag::GPS_ALTITUDE_REF, 1, 3, $inlineValue);
        $ifd0        = pack('v', 1) . $entry . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a Classic TIFF blob containing a BYTE tag with a high-bit value.
     */
    private function buildClassicHighByteTagBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $inlineValue = $this->inlineBytes([0xFF], 4);
        $entry       = self::packClassicEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, $inlineValue);
        $ifd0        = pack('v', 1) . $entry . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a BigTIFF little-endian EXIF payload with nested IFDs.
     */
    private static function buildBigTiffBlob(): string
    {
        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $tileOffsetsValues    = self::BIG_TIFF_TILE_OFFSETS;
        $tileByteCountsValues = self::BIG_TIFF_TILE_BYTE_COUNTS;

        $makeData   = "BigCamXL\0";
        $makeLength = strlen($makeData);

        $gpsLatData = self::packRationalTripletLE([51, 1], [30, 1], [15, 1]);
        $gpsLonData = self::packRationalTripletLE([8, 1], [12, 1], [30, 1]);

        $tileOffsetsData    = implode('', array_map([self::class, 'packUInt64LE'], $tileOffsetsValues));
        $tileByteCountsData = implode('', array_map([self::class, 'packUInt64LE'], $tileByteCountsValues));

        $ifd0EntryCount = 8;
        $ifd0Length     = 8 + ($ifd0EntryCount * 20) + 8;
        $baseOffset     = strlen($header) + $ifd0Length;

        $exifEntryCount   = 3;
        $exifIfdLength    = 8 + ($exifEntryCount * 20) + 8;
        $interopIfdLength = 8 + 20 + 8;
        $gpsEntryCount    = 6;
        $gpsIfdLength     = 8 + ($gpsEntryCount * 20) + 8;

        $cursor = $baseOffset;

        $makeOffset = $cursor;
        $cursor += $makeLength;
        $cursor = self::alignOffset($cursor, 8);

        $exifIfdOffset = $cursor;
        $cursor += $exifIfdLength;
        $cursor = self::alignOffset($cursor, 8);

        $interopIfdOffset = $cursor;
        $cursor += $interopIfdLength;
        $cursor = self::alignOffset($cursor, 8);

        $gpsIfdOffset = $cursor;
        $cursor += $gpsIfdLength;

        $gpsLatOffset = $cursor;
        $cursor += strlen($gpsLatData);

        $gpsLonOffset = $cursor;
        $cursor += strlen($gpsLonData);

        $cursor = self::alignOffset($cursor, 8);

        $tileOffsetsOffset = $cursor;
        $cursor += strlen($tileOffsetsData);

        $cursor = self::alignOffset($cursor, 8);

        $tileByteCountsOffset = $cursor;
        $cursor += strlen($tileByteCountsData);

        $ifd0Entries = [
            self::packBigTiffEntry(ExifTag::MAKE, 2, $makeLength, $makeOffset),
            self::packBigTiffEntry(ExifTag::ORIENTATION, 3, 1, 3),
            self::packBigTiffEntry(ExifTag::TILE_WIDTH, 4, 1, self::BIG_TIFF_TILE_WIDTH),
            self::packBigTiffEntry(ExifTag::TILE_LENGTH, 4, 1, self::BIG_TIFF_TILE_LENGTH),
            self::packBigTiffEntry(ExifTag::TILE_OFFSETS, 18, count($tileOffsetsValues), $tileOffsetsOffset),
            self::packBigTiffEntry(ExifTag::TILE_BYTE_COUNTS, 16, count($tileByteCountsValues), $tileByteCountsOffset),
            self::packBigTiffEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
            self::packBigTiffEntry(ExifTag::GPS_IFD_POINTER, 4, 1, $gpsIfdOffset),
        ];
        $ifd0 = pack('V', count($ifd0Entries)) . pack('V', 0) . implode('', $ifd0Entries) . pack('V', 0) . pack('V', 0);

        $exifEntries = [
            self::packBigTiffEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 320),
            self::packBigTiffEntry(
                ExifTag::FOCAL_LENGTH,
                5,
                1,
                self::toLittleEndianInteger(self::packRationalLE(35, 10)),
            ),
            self::packBigTiffEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, $interopIfdOffset),
        ];
        $exifIfd = pack('V', count($exifEntries)) . pack('V', 0) . implode('', $exifEntries) . pack('V', 0) . pack('V', 0);

        $interopEntries = [
            self::packBigTiffEntry(
                ExifTag::INTEROPERABILITY_INDEX,
                2,
                4,
                self::inlineAsciiToInt('R98', 8),
            ),
        ];
        $interopIfd = pack('V', count($interopEntries)) . pack('V', 0) . implode('', $interopEntries) . pack('V', 0) . pack('V', 0);

        $gpsEntries = [
            self::packBigTiffEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, self::inlineAsciiToInt('N', 8)),
            self::packBigTiffEntry(ExifTag::GPS_LATITUDE, 5, 3, $gpsLatOffset),
            self::packBigTiffEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, self::inlineAsciiToInt('W', 8)),
            self::packBigTiffEntry(ExifTag::GPS_LONGITUDE, 5, 3, $gpsLonOffset),
            self::packBigTiffEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            self::packBigTiffEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                self::toLittleEndianInteger(self::packRationalLE(500, 10)),
            ),
        ];
        $gpsIfd = pack('V', count($gpsEntries)) . pack('V', 0) . implode('', $gpsEntries) . pack('V', 0) . pack('V', 0);

        $blob = $header . $ifd0;

        self::padBufferTo($blob, $makeOffset);
        $blob .= $makeData;

        self::padBufferTo($blob, $exifIfdOffset);
        $blob .= $exifIfd;

        self::padBufferTo($blob, $interopIfdOffset);
        $blob .= $interopIfd;

        self::padBufferTo($blob, $gpsIfdOffset);
        $blob .= $gpsIfd;

        self::padBufferTo($blob, $gpsLatOffset);
        $blob .= $gpsLatData;

        self::padBufferTo($blob, $gpsLonOffset);
        $blob .= $gpsLonData;

        self::padBufferTo($blob, $tileOffsetsOffset);
        $blob .= $tileOffsetsData;

        self::padBufferTo($blob, $tileByteCountsOffset);
        $blob .= $tileByteCountsData;

        return $blob;
    }

    /**
     * Builds a minimal BigTIFF payload containing an inline UNDEFINED value with the high bit set.
     */
    private static function buildBigTiffInlineUndefinedHighBitBlob(): string
    {
        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $entry = self::packBigTiffEntry(
            self::BIG_TIFF_INLINE_UNDEFINED_TAG,
            7,
            strlen(self::BIG_TIFF_INLINE_UNDEFINED_BYTES),
            [0x00000001, 0x80000000],
        );

        $ifd0 = pack('V', 1) . pack('V', 0) . $entry . pack('V', 0) . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a minimal BigTIFF payload containing an inline DOUBLE value.
     */
    private static function buildBigTiffInlineNegativeDoubleBlob(): string
    {
        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $components = self::inlineDoubleComponents(-5.0);

        $entry = self::packBigTiffEntry(
            ExifTag::CAMERA_YAW_DEGREE,
            12,
            1,
            $components,
        );

        $ifd0 = pack('V', 1) . pack('V', 0) . $entry . pack('V', 0) . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a BigTIFF payload exercising LONG8/SLONG8/IFD8 field types with offsets beyond 4 GB.
     */
    private static function buildBigTiffLong8OffsetsBlob(): string
    {
        $stripOffsetsValues    = self::BIG_TIFF_LONG8_STRIP_OFFSETS;
        $stripByteCountsValues = self::BIG_TIFF_LONG8_STRIP_BYTE_COUNTS;

        $entryCount   = 4;
        $headerLength = 16;
        $ifdLength    = 8 + ($entryCount * 20) + 8;

        $stripOffsetsOffset    = $headerLength + $ifdLength;
        $stripOffsetsData      = implode('', array_map([self::class, 'packUInt64LE'], $stripOffsetsValues));
        $stripByteCountsOffset = $stripOffsetsOffset + strlen($stripOffsetsData);
        $stripByteCountsData   = implode('', array_map([self::class, 'packUInt64LE'], $stripByteCountsValues));

        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $ifd0Entries = [
            self::packBigTiffEntry(ExifTag::STRIP_OFFSETS, 16, count($stripOffsetsValues), $stripOffsetsOffset),
            self::packBigTiffEntry(ExifTag::STRIP_BYTE_COUNTS, 16, count($stripByteCountsValues), $stripByteCountsOffset),
            self::packBigTiffEntry(self::CUSTOM_SIGNED_LONG8_TAG, 17, 1, self::BIG_TIFF_LONG8_SIGNED),
            self::packBigTiffEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 18, 1, self::BIG_TIFF_LONG8_JPEG_OFFSET),
        ];

        $ifd0 = pack('V', count($ifd0Entries)) . pack('V', 0) . implode('', $ifd0Entries) . pack('V', 0) . pack('V', 0);

        return $header . $ifd0 . $stripOffsetsData . $stripByteCountsData;
    }

    /**
     * Builds a BigTIFF payload that uses 16-byte offsets for strips and tiles.
     */
    private static function buildBigTiffOffset16Blob(): string
    {
        $offsetSize = 16;
        $header     = 'II'
            . pack('v', 0x002B)
            . pack('v', $offsetSize)
            . pack('v', 0)
            . self::encodeBigTiffValueField(24, $offsetSize);

        $entryCount = 4;
        $entrySize  = 12 + $offsetSize;
        $ifdLength  = 8 + ($entryCount * $entrySize) + $offsetSize;
        $baseOffset = strlen($header) + $ifdLength;

        $stripOffsetsData    = implode('', array_map([self::class, 'packUInt64LE'], self::BIG_TIFF_OFFSET16_STRIP_OFFSETS));
        $stripByteCountsData = implode('', array_map([self::class, 'packUInt64LE'], self::BIG_TIFF_OFFSET16_STRIP_BYTE_COUNTS));
        $tileOffsetsData     = implode('', array_map([self::class, 'packUInt64LE'], self::BIG_TIFF_OFFSET16_TILE_OFFSETS));
        $tileByteCountsData  = implode('', array_map([self::class, 'packUInt64LE'], self::BIG_TIFF_OFFSET16_TILE_BYTE_COUNTS));

        $cursor = $baseOffset;

        $cursor = self::alignOffset($cursor, 16);

        $stripOffsetsOffset = $cursor;
        $cursor += strlen($stripOffsetsData);

        $cursor                = self::alignOffset($cursor, 16);
        $stripByteCountsOffset = $cursor;
        $cursor += strlen($stripByteCountsData);

        $cursor            = self::alignOffset($cursor, 16);
        $tileOffsetsOffset = $cursor;
        $cursor += strlen($tileOffsetsData);

        $cursor               = self::alignOffset($cursor, 16);
        $tileByteCountsOffset = $cursor;
        $cursor += strlen($tileByteCountsData);

        $entries = [
            self::packBigTiffEntry(
                ExifTag::STRIP_OFFSETS,
                16,
                count(self::BIG_TIFF_OFFSET16_STRIP_OFFSETS),
                $stripOffsetsOffset,
                $offsetSize,
            ),
            self::packBigTiffEntry(
                ExifTag::STRIP_BYTE_COUNTS,
                16,
                count(self::BIG_TIFF_OFFSET16_STRIP_BYTE_COUNTS),
                $stripByteCountsOffset,
                $offsetSize,
            ),
            self::packBigTiffEntry(
                ExifTag::TILE_OFFSETS,
                18,
                count(self::BIG_TIFF_OFFSET16_TILE_OFFSETS),
                $tileOffsetsOffset,
                $offsetSize,
            ),
            self::packBigTiffEntry(
                ExifTag::TILE_BYTE_COUNTS,
                16,
                count(self::BIG_TIFF_OFFSET16_TILE_BYTE_COUNTS),
                $tileByteCountsOffset,
                $offsetSize,
            ),
        ];

        $ifd0 = self::packBigTiffIfd($entries, $offsetSize);

        $blob = $header . $ifd0;

        self::padBufferTo($blob, $stripOffsetsOffset);
        $blob .= $stripOffsetsData;

        self::padBufferTo($blob, $stripByteCountsOffset);
        $blob .= $stripByteCountsData;

        self::padBufferTo($blob, $tileOffsetsOffset);
        $blob .= $tileOffsetsData;

        self::padBufferTo($blob, $tileByteCountsOffset);
        $blob .= $tileByteCountsData;

        return $blob;
    }

    /**
     * Builds a BigTIFF payload where an IFD pointer exceeds the signed 63-bit range.
     */
    private static function buildBigTiffOutOfRangeOffsetBlob(): string
    {
        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $entries = [
            self::packBigTiffEntry(ExifTag::GPS_IFD_POINTER, 18, 1, [0x00000001, 0x80000000]),
        ];

        $ifd0 = pack('V', count($entries))
            . pack('V', 0)
            . implode('', $entries)
            . pack('V', 0)
            . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a minimal Classic TIFF payload containing scene and software tags inline.
     */
    private function buildClassicSceneSoftwareBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $exifIfdOffset = 8 + 2 + 12 + 4;

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifIfdOffset),
        ];
        $ifd0 = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);

        $entries = [
            [
                'tag'   => ExifTag::SCENE_TYPE,
                'type'  => 7,
                'count' => 1,
                'value' => self::inlineAsciiToInt("\x01", 4),
            ],
            [
                'tag'   => ExifTag::CUSTOM_RENDERED,
                'type'  => 3,
                'count' => 1,
                'value' => 1,
            ],
            [
                'tag'   => ExifTag::CFA_PATTERN,
                'type'  => 7,
                'count' => 4,
                'value' => self::inlineAsciiToInt("\x00\x01\x02\x03", 4),
            ],
            [
                'tag'   => ExifTag::COMPONENTS_CONFIGURATION,
                'type'  => 7,
                'count' => 4,
                'value' => self::inlineAsciiToInt("\x01\x02\x03\x00", 4),
            ],
        ];

        $asciiTags = [
            ExifTag::IMAGE_TITLE               => 'Cliffside Dusk',
            ExifTag::PHOTOGRAPHER              => 'Alex Light',
            ExifTag::IMAGE_EDITOR              => 'Chris Edit',
            ExifTag::CAMERA_FIRMWARE           => 'Firmware 2.0',
            ExifTag::RAW_DEVELOPING_SOFTWARE   => 'RawLab Studio',
            ExifTag::IMAGE_EDITING_SOFTWARE    => 'EditLab Pro',
            ExifTag::METADATA_EDITING_SOFTWARE => 'MetaLab Suite',
            ExifTag::CAMERA_OWNER_NAME         => 'Ann',
        ];

        $entryCount       = count($entries) + count($asciiTags);
        $exifIfdSize      = 2 + ($entryCount * 12) + 4;
        $nextDataOffset   = $exifIfdOffset + $exifIfdSize;
        $stringDataBuffer = '';

        foreach ($asciiTags as $tag => $value) {
            $encoded = $value . "\0";
            $length  = strlen($encoded);

            if ($length <= 4) {
                $entries[] = [
                    'tag'   => $tag,
                    'type'  => 2,
                    'count' => $length,
                    'value' => self::inlineAsciiToInt($encoded, 4),
                ];

                continue;
            }

            $entries[] = [
                'tag'   => $tag,
                'type'  => 2,
                'count' => $length,
                'value' => $nextDataOffset,
            ];

            $stringDataBuffer .= $encoded;
            $nextDataOffset += $length;
        }

        usort($entries, static fn (array $left, array $right): int => $left['tag'] <=> $right['tag']);

        $exifEntries = array_map(
            static fn (array $entry): string => self::packClassicEntry(
                $entry['tag'],
                $entry['type'],
                $entry['count'],
                $entry['value'],
            ),
            $entries,
        );

        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd . $stringDataBuffer;
    }

    /**
     * Packs a Classic TIFF directory entry.
     *
     * @param int $tag           TIFF tag identifier.
     * @param int $type          TIFF field type code.
     * @param int $count         Number of values represented.
     * @param int $valueOrOffset Inline value or data offset.
     */
    private static function packClassicEntry(int $tag, int $type, int $count, int $valueOrOffset): string
    {
        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valueOrOffset);
    }

    /**
     * Builds a Classic TIFF blob containing maker note data referenced from the EXIF IFD.
     *
     * @return array{0: string, 1: string}
     */
    private function buildClassicMakerNoteBlob(?int $safety = null): array
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $makeString  = "AcmeCam\0";
        $modelString = "ZX-1\0";
        $makerNote   = pack('V', 8) . 'NOTEDATA';

        $ifd0EntryCount = 3;
        $ifd0Size       = 2 + $ifd0EntryCount * 12 + 4;
        $makeOffset     = 8 + $ifd0Size;
        $modelOffset    = $makeOffset + strlen($makeString);
        $exifOffset     = $modelOffset + strlen($modelString);

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::MAKE, 2, strlen($makeString), $makeOffset),
            self::packClassicEntry(ExifTag::MODEL, 2, strlen($modelString), $modelOffset),
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, $exifOffset),
        ];

        $blob = $header;
        $blob .= pack('v', $ifd0EntryCount) . implode('', $ifd0Entries) . pack('V', 0);
        $blob .= $makeString;
        $blob .= $modelString;

        $exifEntryCount  = $safety === null ? 1 : 2;
        $exifIfdSize     = 2 + $exifEntryCount * 12 + 4;
        $makerNoteOffset = $exifOffset + $exifIfdSize;
        $exifEntries     = [
            self::packClassicEntry(ExifTag::MAKER_NOTE, 7, strlen($makerNote), $makerNoteOffset),
        ];
        if ($safety !== null) {
            $exifEntries[] = self::packClassicEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, $safety);
        }

        $blob .= pack('v', $exifEntryCount) . implode('', $exifEntries) . pack('V', 0);
        $blob .= $makerNote;

        return [$blob, $makerNote];
    }

    /**
     * Packs a BigTIFF directory entry in little-endian order.
     *
     * @param int                  $tag           TIFF tag identifier.
     * @param int                  $type          TIFF field type code.
     * @param int                  $count         Number of values represented.
     * @param int|list<int>|string $valueOrOffset Inline value or data offset.
     * @param int                  $fieldWidth    Size of the value/offset field.
     *
     * @phpstan-param int|list<int>|string $valueOrOffset
     */
    private static function packBigTiffEntry(
        int $tag,
        int $type,
        int $count,
        int|array|string $valueOrOffset,
        int $fieldWidth = 8,
    ): string {
        [$countLo, $countHi] = self::splitUInt64($count);

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $countLo)
            . pack('V', $countHi)
            . self::encodeBigTiffValueField($valueOrOffset, $fieldWidth);
    }

    /**
     * Packs a BigTIFF IFD body with the provided entries and next-offset value.
     *
     * @param list<string> $entries
     */
    private static function packBigTiffIfd(array $entries, int $fieldWidth, int $nextOffset = 0): string
    {
        return pack('V', count($entries))
            . pack('V', 0)
            . implode('', $entries)
            . self::encodeBigTiffValueField($nextOffset, $fieldWidth);
    }

    /**
     * Encodes a value for storage in the BigTIFF value/offset field.
     *
     * @param int|list<int>|string $valueOrOffset
     *
     * @phpstan-param int|list<int>|string $valueOrOffset
     */
    private static function encodeBigTiffValueField(int|array|string $valueOrOffset, int $fieldWidth): string
    {
        if ($fieldWidth % 4 !== 0) {
            throw new RuntimeException('BigTIFF field width must be a multiple of four bytes.');
        }

        if (is_string($valueOrOffset)) {
            if (strlen($valueOrOffset) > $fieldWidth) {
                throw new RuntimeException('Inline value exceeds BigTIFF field width.');
            }

            return str_pad($valueOrOffset, $fieldWidth, "\0");
        }

        $wordCount = (int) ($fieldWidth / 4);
        $words     = [];

        if (is_array($valueOrOffset)) {
            /** @var list<int> $valueOrOffset */
            foreach ($valueOrOffset as $word) {
                $words[] = $word & 0xFFFFFFFF;
            }
        } else {
            [$valueLo, $valueHi] = self::splitUInt64Components($valueOrOffset);
            $words[]             = $valueLo;
            $words[]             = $valueHi;
        }

        if (count($words) > $wordCount) {
            throw new RuntimeException('Too many components for BigTIFF value field.');
        }

        while (count($words) < $wordCount) {
            $words[] = 0;
        }

        $bytes = '';
        foreach ($words as $word) {
            $bytes .= pack('V', $word);
        }

        return $bytes;
    }

    /**
     * Converts a short ASCII string into an inline integer value for TIFF entries.
     *
     * @param string $ascii ASCII string to encode.
     * @param int    $width Inline storage width (4 for Classic, 8 for BigTIFF).
     */
    private static function inlineAsciiToInt(string $ascii, int $width): int
    {
        $bytes = str_pad($ascii, $width, "\0");

        return self::toLittleEndianInteger($bytes);
    }

    /**
     * Splits a little-endian DOUBLE value into inline low/high 32-bit components.
     *
     * @return array{0:int,1:int}
     */
    private static function inlineDoubleComponents(float $value): array
    {
        $parts = unpack('V2', pack('e', $value));
        if ($parts === false) {
            throw new RuntimeException('Unable to split inline DOUBLE components.');
        }

        /** @var array{1:int,2:int} $parts */

        return [
            $parts[1] & 0xFFFFFFFF,
            $parts[2] & 0xFFFFFFFF,
        ];
    }

    /**
     * Packs raw bytes into an inline integer value for Classic TIFF entries.
     *
     * @param array<int, int> $values Byte values to encode.
     * @param int             $width  Inline storage width.
     */
    private function inlineBytes(array $values, int $width): int
    {
        $bytes = pack('C*', ...$values);
        $bytes = str_pad($bytes, $width, "\0");

        return self::toLittleEndianInteger($bytes);
    }

    /**
     * Packs three rationals for GPS coordinates using little-endian order.
     *
     * @param array{0:int,1:int} $deg Degree component as a rational pair.
     * @param array{0:int,1:int} $min Minute component as a rational pair.
     * @param array{0:int,1:int} $sec Second component as a rational pair.
     */
    private static function packRationalTripletLE(array $deg, array $min, array $sec): string
    {
        return self::packRationalLE($deg[0], $deg[1])
            . self::packRationalLE($min[0], $min[1])
            . self::packRationalLE($sec[0], $sec[1]);
    }

    /**
     * Packs a single rational number using little-endian byte order.
     *
     * @param int $numerator   Rational numerator.
     * @param int $denominator Rational denominator.
     */
    private static function packRationalLE(int $numerator, int $denominator): string
    {
        return pack('V', $numerator) . pack('V', $denominator);
    }

    /**
     * Encodes a list of Unicode code points into a UTF-16LE string with a NUL terminator.
     *
     * @param list<int> $codePoints
     */
    private static function packUtf16LeString(array $codePoints): string
    {
        $bytes = '';

        foreach ($codePoints as $codePoint) {
            if ($codePoint <= 0xFFFF) {
                $bytes .= pack('v', $codePoint);
                continue;
            }

            $codePoint -= 0x10000;
            $high = 0xD800 | ($codePoint >> 10);
            $low  = 0xDC00 | ($codePoint & 0x3FF);

            $bytes .= pack('v*', $high, $low);
        }

        return $bytes . "\0\0";
    }

    /**
     * Converts a little-endian byte string into an integer value.
     *
     * @param string $bytes Input bytes (LSB first).
     */
    private static function toLittleEndianInteger(string $bytes): int
    {
        $value = 0;
        $len   = strlen($bytes);

        for ($i = $len - 1; $i >= 0; --$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * Aligns an offset to the provided power-of-two boundary.
     */
    private static function alignOffset(int $offset, int $alignment): int
    {
        $remainder = $offset % $alignment;

        if ($remainder === 0) {
            return $offset;
        }

        return $offset + ($alignment - $remainder);
    }

    /**
     * Pads the buffer with NUL bytes until it reaches the requested offset.
     */
    private static function padBufferTo(string &$buffer, int $targetOffset): void
    {
        $length = strlen($buffer);

        if ($length > $targetOffset) {
            throw new RuntimeException('Buffer already beyond target offset.');
        }

        if ($length === $targetOffset) {
            return;
        }

        $buffer .= str_repeat("\0", $targetOffset - $length);
    }

    /**
     * Splits an unsigned 64-bit integer into low/high 32-bit components.
     *
     * @param int $value Input integer to split.
     *
     * @return array{0:int,1:int}
     */
    private static function splitUInt64(int $value): array
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;

        return [$lo, $hi];
    }

    /**
     * Packs a 64-bit integer into little-endian byte order.
     *
     * @param int|array{0:int,1:int} $value 64-bit integer or low/high components.
     *
     * @phpstan-param int|array{0:int,1:int} $value
     */
    private static function packUInt64LE(int|array $value): string
    {
        [$lo, $hi] = self::splitUInt64Components($value);

        return pack('V', $lo) . pack('V', $hi);
    }

    /**
     * Normalises an unsigned 64-bit representation into low/high components.
     *
     * @param int|array{0:int,1:int} $value Unsigned integer or explicit low/high pair.
     *
     * @return array{0:int,1:int}
     */
    private static function splitUInt64Components(int|array $value): array
    {
        if (is_array($value)) {
            /** @var array{0:int,1:int} $value */
            $components = $value;
            [$lo, $hi]  = $components;

            return [$lo & 0xFFFFFFFF, $hi & 0xFFFFFFFF];
        }

        return self::splitUInt64($value);
    }
}
