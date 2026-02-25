<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

use function pack;
use function str_pad;
use function strlen;
use function substr;

/**
 * Exercises malformed TIFF/EXIF inputs to ensure strict rejection behavior.
 * It targets invalid headers, broken offsets, and corrupt IFD structures.
 * The suite expects ParseError or BoundsError instead of partial or misleading output.
 * This enforces defensive parsing when encountering damaged TIFF payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(BoundsError::class)]
#[UsesClass(Compression::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(Endian::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class TiffExifParserNegativeTest extends TestCase
{
    /**
     * Uses a bogus byte-order marker instead of II/MM.
     * Confirms the parser raises ParseError for an invalid byte order value.
     */
    #[Test]
    public function rejectsInvalidByteOrderMarker(): void
    {
        $blob = 'XX' . pack('n', TiffConst::MAGIC_CLASSIC) . pack('N', 8);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Bad TIFF byte order');

        $reader->parseFromBlob($blob);
    }

    /**
     * Supplies a TIFF header with an unknown magic number.
     * Ensures the parser rejects the header with a ParseError.
     */
    #[Test]
    public function rejectsInvalidMagicNumber(): void
    {
        $blob = 'II' . pack('v', 0x9999) . pack('V', 8);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unknown TIFF magic');

        $reader->parseFromBlob($blob);
    }

    /**
     * Points the first IFD offset beyond the available blob size.
     * Verifies a BoundsError is thrown for the out-of-range offset.
     */
    #[Test]
    public function rejectsIfdOffsetBeyondBlobSize(): void
    {
        // Classic TIFF with first IFD offset pointing way beyond blob
        $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 0xFFFFFF);

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Sets the first IFD offset but provides no data at that location.
     * Ensures the parser rejects the truncated IFD header with BoundsError.
     */
    #[Test]
    public function rejectsTruncatedIfdHeader(): void
    {
        // Header points to offset 8, but no data there
        $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 8);

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a BigTIFF header with an invalid offset size of 4 bytes.
     * Confirms the parser throws ParseError for unsupported offset sizes.
     */
    #[Test]
    public function rejectsBigTiffWithInvalidOffsetSize(): void
    {
        // BigTIFF magic (0x002B) with invalid offset size (4 instead of 8 or 16)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 4)      // Invalid offset size
            . pack('v', 0)      // Reserved
            . pack('P', 16);    // First IFD offset

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported BigTIFF offset size');

        $reader->parseFromBlob($blob);
    }

    /**
     * Sets the BigTIFF reserved field to a non-zero value.
     * Ensures the parser flags the header as invalid with ParseError.
     */
    #[Test]
    public function rejectsBigTiffWithNonZeroReserved(): void
    {
        // BigTIFF with reserved field != 0
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)      // Offset size
            . pack('v', 42)     // Reserved (should be 0)
            . pack('P', 16);    // First IFD offset

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Bad BigTIFF header');

        $reader->parseFromBlob($blob);
    }

    /**
     * Uses a RATIONAL value whose denominator is zero.
     * Confirms the parser tolerates the degenerate fraction without throwing.
     */
    #[Test]
    public function handlesRationalWithZeroDenominator(): void
    {
        // Create a minimal valid TIFF with one IFD entry containing a RATIONAL with denominator = 0
        $blob = $this->buildMinimalTiffWithRational(100, 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Uses SRATIONAL values at the signed 32-bit extremes.
     * Ensures the parser accepts extreme signed values without errors.
     */
    #[Test]
    public function handlesSrationalWithExtremeValues(): void
    {
        // SRATIONAL with INT_MIN and INT_MAX
        $blob = $this->buildMinimalTiffWithSRational(-2147483648, 2147483647);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Declares an IFD entry count that would overflow a classic TIFF.
     * Verifies the parser rejects the header with a ParseError.
     */
    #[Test]
    public function rejectsIfdWithHugeEntryCount(): void
    {
        // Classic TIFF with IFD at offset 8, claiming 65535 entries (would overflow)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)      // First IFD offset
            . pack('v', 65535); // Huge entry count

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * A cyclic IFD chain is silently broken — only the first visit is kept.
     */
    #[Test]
    public function toleratesCyclicIfdChain(): void
    {
        // Create TIFF where IFD0's next pointer points back to itself
        $ifdOffset = 8;
        $blob      = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)  // First IFD at offset 8
            . pack('v', 2)           // 2 entries in IFD
            . pack('v', ExifTag::IMAGE_WIDTH) . pack('v', TiffConst::TYPE_SHORT) . pack('V', 1) . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH) . pack('v', TiffConst::TYPE_SHORT) . pack('V', 1) . pack('v', 100) . pack('v', 0)
            . pack('V', $ifdOffset); // Next IFD points back to offset 8 (cycle)

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a TIFF entry using an invalid field type code.
     * Ensures the parser rejects unsupported TIFF types with ParseError.
     */
    /**
     * Unknown TIFF type codes are silently skipped — the entry is omitted.
     */
    #[Test]
    public function toleratesUnsupportedTiffType(): void
    {
        // Build TIFF with an entry using invalid type code (99)
        $blob = $this->buildTiffWithInvalidType(99);

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        // The unknown-type entry is silently skipped
        self::assertNull($parsed->ifd0->get(0x010F));
    }

    /**
     * Truncates the IFD entry so mandatory fields are missing.
     * Verifies a BoundsError is thrown for the incomplete entry data.
     */
    #[Test]
    public function rejectsTruncatedIfdEntry(): void
    {
        // IFD claiming 1 entry but data is truncated
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)          // IFD at offset 8
            . pack('v', 1)          // 1 entry
            . pack('v', 0x010F);    // Tag: Manufacturer (partial entry)
        // Missing: type (2 bytes), count (4 bytes), value/offset (4 bytes), next IFD offset (4 bytes)

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Accepts ASCII entry whose declared payload omits the trailing NUL (Postel's Law).
     * Many legacy cameras omit the NUL terminator in ASCII values.
     */
    #[Test]
    public function acceptsAsciiValueWithoutNullTerminator(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::MAKE)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 4)
            . pack('V', 0x44434241)
            . pack('V', 0);

        $result = (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);

        self::assertSame('ABCD', $result->cameraMake());
    }

    /**
     * Accepts an IFD with descending tag identifiers.
     * TIFF 6.0 §2 sorting is a writer-side constraint; unsorted IFDs are
     * common from mobile devices and tolerated per Postel's law.
     */
    #[Test]
    public function acceptsUnsortedIfdEntries(): void
    {
        // Artist (0x013B) before Software (0x0131) — descending order
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 2)
            . pack('v', ExifTag::ARTIST)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 4)
            . pack('V', 0x00434241)
            . pack('v', ExifTag::SOFTWARE)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 4)
            . pack('V', 0x00434241)
            . pack('V', 0);

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            // Any error other than the old sort-order rejection is acceptable
            // here — the minimal blob lacks required IFD0 tags, so later
            // validation may still throw.
            self::assertNotSame(1308, $e->getCode());

            return;
        }

        // If no exception, the unsorted entries were accepted too.
        $this->addToAssertionCount(1);
    }

    /**
     * Accepts ExifIFDPointer with count=2 (Postel's Law — uses first offset).
     * The synthetic blob triggers a BoundsError downstream, but no longer
     * the count-validation ParseError — confirming the tolerance works.
     */
    #[Test]
    public function acceptsExifIfdPointerWithNonSingleCount(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::EXIF_IFD_POINTER, TiffConst::TYPE_LONG, 2);

        try {
            (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);
        } catch (Throwable $e) {
            // The count-validation ParseError (1340) must not appear.
            self::assertNotSame(1340, $e->getCode());

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSInfoIFDPointer with count=3 (Postel's Law — uses first offset).
     */
    #[Test]
    public function acceptsGpsIfdPointerWithNonSingleCount(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::GPS_IFD_POINTER, TiffConst::TYPE_LONG, 3);

        try {
            (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);
        } catch (Throwable $e) {
            self::assertNotSame(1340, $e->getCode());

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Creates a GPSInfoIFDPointer entry with type ASCII instead of LONG.
     * Postel's Law: the parser tolerates wrong field types.
     */
    #[Test]
    public function toleratesGpsIfdPointerWithBadType(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::GPS_IFD_POINTER, TiffConst::TYPE_ASCII, 1);

        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($parsed->gpsIfd);
    }

    /**
     * Feeds fixed-length tags with invalid counts via a data provider.
     * Confirms the parser rejects each case with the expected ParseError message.
     */
    #[Test]
    #[DataProvider('invalidFixedLengthTagProvider')]
    public function rejectsFixedLengthTagsWithInvalidCounts(
        int $tag,
        int $type,
        int $count,
        string $valueBytes,
        string $expectedMessage,
    ): void {
        $blob = $this->buildClassicTiffWithEntry($tag, $type, $count, $valueBytes);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage($expectedMessage);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a classic TIFF with a LONG8 field type in IFD0.
     * Confirms the parser rejects BigTIFF-only types in classic TIFF.
     */
    /**
     * BigTIFF-only LONG8 type in classic TIFF is silently skipped.
     */
    #[Test]
    public function toleratesLong8InClassicTiff(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_LONG8, 1);

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        self::assertNull($parsed->ifd0->get(0x0100));
    }

    /**
     * BigTIFF-only SLONG8 type in classic TIFF is silently skipped.
     */
    #[Test]
    public function toleratesSlong8InClassicTiff(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_SLONG8, 1);

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        self::assertNull($parsed->ifd0->get(0x0100));
    }

    /**
     * BigTIFF-only IFD8 type in classic TIFF is silently skipped.
     */
    #[Test]
    public function toleratesIfd8InClassicTiff(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_IFD8, 1);

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        self::assertNull($parsed->ifd0->get(0x0100));
    }

    /**
     * Builds a minimal valid TIFF blob with a RATIONAL value.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $numerator   Numerator for the rational.
     * @param int $denominator Denominator for the rational.
     *
     * @return string Binary TIFF blob.
     */
    private function buildMinimalTiffWithRational(int $numerator, int $denominator): string
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        // TIFF header
        $blob = 'II'  // Little-endian
            . pack('v', TiffConst::MAGIC_CLASSIC)  // Classic TIFF magic
            . pack('V', $ifdOffset);  // First IFD at offset 8

        // IFD0 with 3 entries
        $blob .= pack('v', $entryCount);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Entry: Tag=0x011A (XResolution), Type=RATIONAL(5), Count=1
        $blob .= pack('v', 0x011A)       // Tag
            . pack('v', TiffConst::TYPE_RATIONAL)  // Type
            . pack('V', 1)               // Count
            . pack('V', $valOffset);     // Offset to rational data

        // Next IFD offset (none)
        $blob .= pack('V', 0);

        // Rational data: numerator and denominator
        $blob .= pack('V', $numerator)
            . pack('V', $denominator);

        return $blob;
    }

    /**
     * Builds a TIFF blob with IFD0 carrying a malformed pointer entry directly.
     * This checks the behavior for invalid ExifIFDPointer or GPSInfoIFDPointer layouts.
     *
     * @param int $tag   Tag identifier for the pointer entry.
     * @param int $type  Field type used for the pointer entry.
     * @param int $count Value count stored for the pointer entry.
     */
    private function buildTiffWithIfd0Pointer(int $tag, int $type, int $count): string
    {
        $ifd0Offset = 8;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        // IFD0 with the malformed pointer entry
        $blob .= pack('v', 1)
            . pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', 0)
            . pack('V', 0);

        return $blob;
    }

    /**
     * Builds a classic TIFF with an Exif IFD containing one SHORT[1] enum-tag value.
     *
     * @param int $tag   Exif IFD tag identifier.
     * @param int $value SHORT[1] scalar value for the tag.
     */
    private function buildTiffWithExifShortTag(int $tag, int $value): string
    {
        $ifd0Offset     = 8;
        $ifd0EntryCount = 3;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $exifIfdOffset  = $ifd0Offset + $ifd0Size;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        $blob .= pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        return $blob . (pack('v', 1) . pack('v', $tag) . pack('v', TiffConst::TYPE_SHORT) . pack('V', 1) . pack('v', $value) . pack('v', 0) . pack('V', 0));
    }

    /**
     * Builds a classic TIFF with CompositeImage and optional companion tags in Exif IFD.
     *
     * @param int                     $compositeImageValue CompositeImage SHORT[1] value.
     * @param array{0:int,1:int}|null $sourceImageNumber   SourceImageNumberOfCompositeImage SHORT[2] pair.
     * @param string|null             $sourceExposureTimes SourceExposureTimesOfCompositeImage UNDEFINED payload.
     */
    private function buildTiffWithCompositeExifTags(
        int $compositeImageValue,
        ?array $sourceImageNumber,
        ?string $sourceExposureTimes,
    ): string {
        $ifd0Offset     = 8;
        $ifd0EntryCount = 3;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $exifIfdOffset  = $ifd0Offset + $ifd0Size;

        $entries = [
            [
                'tag'   => ExifTag::COMPOSITE_IMAGE,
                'type'  => TiffConst::TYPE_SHORT,
                'count' => 1,
                'value' => pack('v', $compositeImageValue),
            ],
        ];

        if ($sourceImageNumber !== null) {
            $entries[] = [
                'tag'   => ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                'type'  => TiffConst::TYPE_SHORT,
                'count' => 2,
                'value' => pack('v', $sourceImageNumber[0]) . pack('v', $sourceImageNumber[1]),
            ];
        }

        if ($sourceExposureTimes !== null) {
            $entries[] = [
                'tag'   => ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE,
                'type'  => TiffConst::TYPE_UNDEFINED,
                'count' => strlen($sourceExposureTimes),
                'value' => $sourceExposureTimes,
            ];
        }

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        $blob .= pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfdEntryCount = count($entries);
        $exifIfdSize       = 2 + ($exifIfdEntryCount * 12) + 4;
        $dataOffset        = $exifIfdOffset + $exifIfdSize;
        $exifIfdBlob       = pack('v', $exifIfdEntryCount);
        $payloadBlob       = '';

        foreach ($entries as $entry) {
            $tag   = $entry['tag'];
            $type  = $entry['type'];
            $count = $entry['count'];
            $value = $entry['value'];
            $size  = $this->bytesPerCompositeComponent($type) * $count;

            if ($size <= 4) {
                $exifIfdBlob .= pack('v', $tag)
                    . pack('v', $type)
                    . pack('V', $count)
                    . str_pad(substr($value, 0, $size), 4, "\0");
                continue;
            }

            $exifIfdBlob .= pack('v', $tag)
                . pack('v', $type)
                . pack('V', $count)
                . pack('V', $dataOffset);

            $payloadBlob .= substr($value, 0, $size);
            $dataOffset += $size;

            if (($dataOffset % 2) !== 0) {
                $payloadBlob .= "\0";
                ++$dataOffset;
            }
        }

        $exifIfdBlob .= pack('V', 0);

        return $blob . $exifIfdBlob . $payloadBlob;
    }

    /**
     * Builds a valid little-endian SourceExposureTimesOfCompositeImage payload.
     */
    private function buildValidCompositeExposurePayload(): string
    {
        return $this->buildCompositeExposureSummaryBytes()
            . pack('v', 1)
            . pack('v', 2)
            . pack('V2', 1, 10)
            . pack('V2', 1, 5);
    }

    /**
     * Builds the summary block (8 RATIONAL values) of the composite exposure payload.
     */
    private function buildCompositeExposureSummaryBytes(): string
    {
        return pack('V2', 5, 1)
            . pack('V2', 3, 1)
            . pack('V2', 4, 1)
            . pack('V2', 3, 1)
            . pack('V2', 2, 1)
            . pack('V2', 1, 2)
            . pack('V2', 2, 1)
            . pack('V2', 1, 3);
    }

    /**
     * Builds a payload where the sequence section ends in a partial RATIONAL.
     */
    private function buildCompositeExposurePayloadWithTruncatedSequenceSection(): string
    {
        return $this->buildCompositeExposureSummaryBytes()
            . pack('v', 1)
            . pack('v', 1)
            . pack('V', 1);
    }

    /**
     * Builds a payload where declared image counts exceed the available payload bytes.
     */
    private function buildCompositeExposurePayloadWithInconsistentCounts(): string
    {
        return $this->buildCompositeExposureSummaryBytes()
            . pack('v', 1)
            . pack('v', 3)
            . pack('V2', 1, 10)
            . pack('V2', 1, 5);
    }

    /**
     * Builds a minimal valid TIFF blob with an SRATIONAL value.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $numerator   Signed numerator.
     * @param int $denominator Signed denominator.
     *
     * @return string Binary TIFF blob.
     */
    private function buildMinimalTiffWithSRational(int $numerator, int $denominator): string
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        // TIFF header
        $blob = 'II'  // Little-endian
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

        // IFD0 with 3 entries
        $blob .= pack('v', $entryCount);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Entry: Tag=0x9201 (ShutterSpeedValue), Type=SRATIONAL(10), Count=1
        $blob .= pack('v', 0x9201)
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', 1)
            . pack('V', $valOffset);

        $blob .= pack('V', 0);  // Next IFD

        // SRATIONAL data (signed 32-bit values)
        $blob .= pack('l', $numerator)
            . pack('l', $denominator);

        return $blob;
    }

    /**
     * Builds a TIFF blob with an IFD entry using an invalid type code.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $invalidType Invalid TIFF type code.
     *
     * @return string Binary TIFF blob.
     */
    private function buildTiffWithInvalidType(int $invalidType): string
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);  // 1 entry

        // Entry with invalid type
        $blob .= pack('v', 0x010F)        // Tag: Manufacturer
            . pack('v', $invalidType)     // Invalid type
            . pack('V', 1)                // Count
            . pack('V', 0x41424300);      // Inline value "ABC\0"

        $blob .= pack('V', 0);  // Next IFD

        return $blob;
    }

    /**
     * @return array<string, array{0:int,1:int,2:int,3:string,4:string}>
     */
    public static function invalidFixedLengthTagProvider(): array
    {
        return [
            'GPSVersionID expects 4 bytes' => [
                ExifTag::GPS_VERSION_ID,
                TiffConst::TYPE_BYTE,
                3,
                "\x02\x03\x00",
                'GPSVersionID must contain exactly 4 bytes per EXIF 3.0 §4.6.7.1.1.',
            ],
            'SubjectLocation expects 2 SHORT' => [
                ExifTag::SUBJECT_LOCATION,
                TiffConst::TYPE_SHORT,
                1,
                "\x00\x64",
                'SubjectLocation must contain exactly 2 bytes per EXIF 3.0 §4.6.6.7.29.',
            ],
            'SubjectLocation rejects LONG type' => [
                ExifTag::SUBJECT_LOCATION,
                TiffConst::TYPE_LONG,
                2,
                "\x00\x00\x00\x64\x00\x00\x00\xC8",
                'SubjectLocation must use TIFF type SHORT per EXIF 3.0 §4.6.6.7.29.',
            ],
            'LensSpecification expects 4 RATIONAL' => [
                ExifTag::LENS_SPECIFICATION,
                TiffConst::TYPE_RATIONAL,
                3,
                "\x00\x00\x00\x1C\x00\x00\x00\x01\x00\x00\x00\x46\x00\x00\x00\x01\x00\x00\x00\x18\x00\x00\x00\x0A",
                'LensSpecification must contain exactly 4 bytes per EXIF 3.0 §4.6.6.9.4.',
            ],
            'WhitePoint expects 2 RATIONAL' => [
                ExifTag::WHITE_POINT,
                TiffConst::TYPE_RATIONAL,
                1,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 1),
                'WhitePoint must contain exactly 2 bytes per EXIF 3.0 §4.6.5.3.2.',
            ],
            'PrimaryChromaticities expects 6 RATIONAL' => [
                ExifTag::PRIMARY_CHROMATICITIES,
                TiffConst::TYPE_RATIONAL,
                5,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 5),
                'PrimaryChromaticities must contain exactly 6 bytes per EXIF 3.0 §4.6.5.3.3.',
            ],
            'YCbCrCoefficients expects 3 RATIONAL' => [
                ExifTag::YCBCR_COEFFICIENTS,
                TiffConst::TYPE_RATIONAL,
                2,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
                'YCbCrCoefficients must contain exactly 3 bytes per EXIF 3.0 §4.6.5.3.4.',
            ],
            'ReferenceBlackWhite expects 6 RATIONAL' => [
                ExifTag::REFERENCE_BLACK_WHITE,
                TiffConst::TYPE_RATIONAL,
                5,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 5),
                'ReferenceBlackWhite must contain exactly 6 bytes per EXIF 3.0 §4.6.5.3.5.',
            ],
            'GPSTimeStamp expects 3 RATIONAL' => [
                ExifTag::GPS_TIME_STAMP,
                TiffConst::TYPE_RATIONAL,
                2,
                "\x00\x00\x00\x0C\x00\x00\x00\x01\x00\x00\x00\x22\x00\x00\x00\x01",
                'GPSTimeStamp must contain exactly 3 bytes per EXIF 3.0 §4.6.7.1.8.',
            ],
            'GPSDateStamp expects 11 ASCII' => [
                ExifTag::GPS_DATE_STAMP,
                TiffConst::TYPE_ASCII,
                10,
                '2024:05:06',
                'GPSDateStamp must contain exactly 11 bytes per EXIF 3.0 §4.6.7.1.30.',
            ],
            'FileSource expects 1 UNDEFINED' => [
                ExifTag::FILE_SOURCE,
                TiffConst::TYPE_UNDEFINED,
                2,
                "\x03\x00",
                'FileSource must contain exactly 1 bytes per EXIF 3.0 §4.6.6.7.32.',
            ],
            'SceneType expects 1 UNDEFINED' => [
                ExifTag::SCENE_TYPE,
                TiffConst::TYPE_UNDEFINED,
                2,
                "\x01\x00",
                'SceneType must contain exactly 1 bytes per EXIF 3.0 §4.6.6.7.33.',
            ],
            'GPSAltitudeRef expects 1 BYTE' => [
                ExifTag::GPS_ALTITUDE_REF,
                TiffConst::TYPE_BYTE,
                2,
                "\x00\x01",
                'GPSAltitudeRef must contain exactly 1 bytes per EXIF 3.0 §4.6.7.1.6.',
            ],
            'GPSDifferential expects 1 SHORT' => [
                ExifTag::GPS_DIFFERENTIAL,
                TiffConst::TYPE_SHORT,
                2,
                "\x01\x00\x00\x00",
                'GPSDifferential must contain exactly 1 bytes per EXIF 3.0 §4.6.7.1.31.',
            ],
            'DNGVersion expects 4 BYTE' => [
                DngTag::DNG_VERSION,
                TiffConst::TYPE_BYTE,
                3,
                "\x01\x07\x01",
                'DNGVersion must contain exactly 4 bytes per DNG 1.7.1.0.',
            ],
            'DNGBackwardVersion expects 4 BYTE' => [
                DngTag::DNG_BACKWARD_VERSION,
                TiffConst::TYPE_BYTE,
                3,
                "\x01\x07\x01",
                'DNGBackwardVersion must contain exactly 4 bytes per DNG 1.7.1.0.',
            ],
            'CFALayout expects 1 SHORT' => [
                DngTag::CFA_LAYOUT,
                TiffConst::TYPE_SHORT,
                2,
                "\x01\x00\x02\x00",
                'CFALayout must contain exactly 1 bytes per DNG 1.7.1.0.',
            ],
            'BaselineExposure expects 1 SRATIONAL' => [
                DngTag::BASELINE_EXPOSURE,
                TiffConst::TYPE_SRATIONAL,
                2,
                str_repeat("\x00\x00\x00\x01\x00\x00\x00\x01", 2),
                'BaselineExposure must contain exactly 1 bytes per DNG 1.7.1.0.',
            ],
            'RawDataUniqueID expects 16 BYTE' => [
                DngTag::RAW_DATA_UNIQUE_ID,
                TiffConst::TYPE_BYTE,
                8,
                str_repeat("\xAB", 8),
                'RawDataUniqueID must contain exactly 16 bytes per DNG 1.7.1.0.',
            ],
        ];
    }

    private function buildClassicTiffWithEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $ifdOffset = 8;
        $blob      = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

        $blob .= pack('v', 1);

        $componentSize = $this->bytesPerComponent($type);
        $dataSize      = $componentSize * $count;

        if (strlen($valueBytes) < $dataSize) {
            $valueBytes = str_pad($valueBytes, $dataSize, "\0");
        }

        if ($dataSize <= 4) {
            $inlineBytes = str_pad(substr($valueBytes, 0, $dataSize), 4, "\0");

            return $blob . (pack('v', $tag) . pack('v', $type) . pack('V', $count) . $inlineBytes . pack('V', 0));
        }

        $valueOffset = $ifdOffset + 2 + 12 + 4;

        $blob .= pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valueOffset)
            . pack('V', 0);

        return $blob . substr($valueBytes, 0, $dataSize);
    }

    /**
     * Differing XResolution and YResolution values are tolerated.
     */
    #[Test]
    public function toleratesDifferingXAndYResolution(): void
    {
        // IFD0 with XResolution=72/1 and YResolution=96/1
        $ifdOffset = 8;
        $ifdCount  = 2;
        $ifdSize   = 2 + ($ifdCount * 12) + 4;
        $valOffset = $ifdOffset + $ifdSize;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $ifdCount)
            // XResolution (0x011A) RATIONAL[1]
            . pack('v', ExifTag::X_RESOLUTION)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 1)
            . pack('V', $valOffset)
            // YResolution (0x011B) RATIONAL[1]
            . pack('v', ExifTag::Y_RESOLUTION)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 1)
            . pack('V', $valOffset + 8)
            . pack('V', 0)
            // XResolution value: 72/1
            . pack('V', 72) . pack('V', 1)
            // YResolution value: 96/1
            . pack('V', 96) . pack('V', 1);

        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNotNull($parsed->ifd0->get(ExifTag::X_RESOLUTION));
        self::assertNotNull($parsed->ifd0->get(ExifTag::Y_RESOLUTION));
    }

    /**
     * YCbCrPositioning value 3 is rejected per EXIF 3.0 §4.6.5.1.13.
     */
    #[Test]
    public function rejectInvalidYCbCrPositioning(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('YCbCrPositioning value 3 is outside the valid domain {0, 1, 2}');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(ExifTag::YCBCR_POSITIONING, 3));
    }

    /**
     * ColorSpace value 2 is rejected per EXIF 3.0 §4.6.6.2.1.
     */
    #[Test]
    public function rejectInvalidColorSpace(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ColorSpace value 2 is outside the valid domain {1, 65535}');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(ExifTag::COLOR_SPACE, 2));
    }

    /**
     * FocalPlaneResolutionUnit value 4 is rejected per EXIF 3.0 §4.6.6.7.28.
     */
    #[Test]
    public function rejectInvalidFocalPlaneResolutionUnit(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FocalPlaneResolutionUnit value 4 is outside the valid domain {2, 3}');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 4));
    }

    /**
     * PlanarConfiguration value 3 is rejected per EXIF 3.0 §4.6.5.1.10.
     */
    #[Test]
    public function rejectInvalidPlanarConfiguration(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('PlanarConfiguration value 3 is outside the valid domain {1, 2}');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(ExifTag::PLANAR_CONFIGURATION, 3));
    }

    /**
     * Predictor value 3 is rejected per TIFF 6.0 §14.
     */
    #[Test]
    public function rejectInvalidPredictor(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1358);
        $this->expectExceptionMessage('Predictor value 3 is outside the valid domain {1, 2} per TIFF 6.0 §14.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(TiffTag::PREDICTOR, 3));
    }

    /**
     * Orientation value 0 is accepted (Postel's Law) — commonly means "unspecified".
     */
    #[Test]
    public function acceptOrientationValueZero(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 0) . pack('v', 0) // value=0 inline
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);

        $this->addToAssertionCount(1);
    }

    /**
     * Orientation value 9 is rejected per EXIF 3.0 §4.6.5.1.6.
     */
    #[Test]
    public function rejectOrientationValueNine(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Orientation value 9 is outside the valid domain 0..8');

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 9) . pack('v', 0) // value=9 inline
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * ASCII value containing bytes > 0x7F is decoded via Latin-1 fallback.
     * Real-world cameras write accented characters in ASCII fields.
     */
    #[Test]
    public function acceptAsciiValueWithNon7BitByteViaLatin1Fallback(): void
    {
        // IFD with one ASCII entry containing 0x80 (>4 bytes to force out-of-line)
        $asciiData = "hello\x80\0\0";
        $ifdOffset = 8;
        $ifdSize   = 2 + 12 + 4;
        $valOffset = $ifdOffset + $ifdSize;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', 1)
            . pack('v', ExifTag::IMAGE_DESCRIPTION)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($asciiData))
            . pack('V', $valOffset)
            . pack('V', 0)
            . $asciiData;

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            // Any error other than the old non-7-bit rejection is acceptable
            // — the minimal blob lacks required IFD0 tags.
            self::assertStringNotContainsString('non-7-bit byte', $e->getMessage());

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Odd first IFD offset is accepted — TIFF 6.0 §2 word-alignment is a
     * writer-side recommendation; the spec instructs readers to accept it.
     */
    #[Test]
    public function acceptOddFirstIfdOffset(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 9) // odd offset
            . str_repeat("\0", 32);

        try {
            (new TiffExifParser())->parseFromBlob($blob);
        } catch (ParseError $e) {
            // Any error other than the old word-alignment rejection is
            // acceptable — the minimal blob may fail later validation.
            self::assertStringNotContainsString('word-aligned', $e->getMessage());

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * IFD with entryCount=0 returns an empty Ifd (Postel's Law).
     */
    #[Test]
    public function returnsEmptyIfdForZeroEntries(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 0)
            . pack('V', 0);

        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame([], $parsed->ifd0->entries);
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_SBYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT,
            TiffConst::TYPE_SSHORT => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            TiffConst::TYPE_DOUBLE    => 8,
            default                   => 1,
        };
    }

    private function bytesPerCompositeComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_SHORT => 2,
            default               => 1,
        };
    }

    /**
     * Builds a minimal classic TIFF with a single SHORT[1] tag.
     */
    private function buildTiffWithShortTag(int $tag, int $value): string
    {
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $value) . pack('v', 0)
            . pack('V', 0);
    }

    /**
     * Builds a minimal classic TIFF with multiple SHORT[1] tags in IFD0.
     *
     * @param list<array{int, int}> $tags Tag/value pairs (SHORT[1])
     */
    private function buildTiffWithShortTags(array $tags): string
    {
        $count = count($tags);
        $blob  = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', $count);

        foreach ($tags as [$tag, $value]) {
            $blob .= pack('v', $tag)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $value) . pack('v', 0);
        }

        return $blob . pack('V', 0);
    }

    /**
     * Builds a minimal TIFF with IFD0 containing given entries plus IFD1 with given entries.
     *
     * @param list<array{int, int}> $ifd0Tags Tag/value pairs (SHORT[1])
     * @param list<array{int, int}> $ifd1Tags Tag/value pairs (SHORT[1])
     */
    private function buildTiffWithTwoIfds(array $ifd0Tags, array $ifd1Tags): string
    {
        $ifd0Count = count($ifd0Tags);
        // IFD0 at offset 8
        // Each entry is 12 bytes, then 4 bytes for next-IFD offset
        $ifd0Size  = 2 + ($ifd0Count * 12) + 4;
        $ifd1Start = 8 + $ifd0Size;

        $ifd1Count = count($ifd1Tags);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        // IFD0
        $blob .= pack('v', $ifd0Count);
        foreach ($ifd0Tags as [$tag, $value]) {
            $blob .= pack('v', $tag)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $value) . pack('v', 0);
        }

        $blob .= pack('V', $ifd1Start);

        // IFD1
        $blob .= pack('v', $ifd1Count);
        foreach ($ifd1Tags as [$tag, $value]) {
            $blob .= pack('v', $tag)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $value) . pack('v', 0);
        }

        return $blob . pack('V', 0);
    }

    /**
     * Builds a classic TIFF with IFD1 JPEG thumbnail tags and appended thumbnail bytes.
     *
     * @param string $thumbnailStream Raw JPEG thumbnail bytes.
     */
    private function buildTiffWithJpegThumbnailStream(string $thumbnailStream): string
    {
        $ifd0EntryCount = 2;
        $ifd1EntryCount = 4;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $ifd1Offset     = 8 + $ifd0Size;
        $ifd1Size       = 2 + ($ifd1EntryCount * 12) + 4;
        $thumbOffset    = $ifd1Offset + $ifd1Size;
        $thumbLength    = strlen($thumbnailStream);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', $ifd0EntryCount)
            . $this->buildIfdShortEntry(ExifTag::IMAGE_WIDTH, 100)
            . $this->buildIfdShortEntry(ExifTag::IMAGE_LENGTH, 100)
            . pack('V', $ifd1Offset);

        $blob .= pack('v', $ifd1EntryCount)
            . $this->buildIfdShortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value)
            . $this->buildIfdShortEntry(TiffTag::JPEG_PROC, 1)
            . $this->buildIfdLongEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, $thumbOffset)
            . $this->buildIfdLongEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, $thumbLength)
            . pack('V', 0);

        return $blob . $thumbnailStream;
    }

    /**
     * Builds a SHORT[1] IFD entry payload.
     */
    private function buildIfdShortEntry(int $tag, int $value): string
    {
        return pack('v', $tag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $value)
            . pack('v', 0);
    }

    /**
     * Builds a LONG[1] IFD entry payload.
     */
    private function buildIfdLongEntry(int $tag, int $value): string
    {
        return pack('v', $tag)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $value);
    }

    /**
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function provideInvalidThumbnailBoundaryStreams(): iterable
    {
        yield 'missing-soi' => [
            "\x00\xD8\xFF\xD9",
            '/thumbnail stream.*missing SOI|missing SOI.*thumbnail stream/i',
        ];

        yield 'missing-eoi' => [
            "\xFF\xD8\xFF\xDB\x00\x04\x00\x00\xFF\x00",
            '/thumbnail stream.*missing EOI|missing EOI.*thumbnail stream/i',
        ];
    }

    /**
     * IFD0 Compression=6 in JPEG context is tolerated as "old-style JPEG".
     * Real-world cameras frequently embed Compression=6 in APP1 IFD0.
     */
    #[Test]
    public function itToleratesCompression6InIfd0JpegContext(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::COMPRESSION, 6),
            jpegContext: true,
        );

        self::assertSame(6, $parsed->ifd0->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * IFD0 Compression=5 (LZW) must be accepted in standalone TIFF context.
     */
    #[Test]
    public function acceptIfd0CompressionLzwInTiffContext(): void
    {
        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTags([
                [ExifTag::IMAGE_WIDTH, 100],
                [ExifTag::IMAGE_LENGTH, 100],
                [ExifTag::COMPRESSION, 5],
            ]),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * IFD0 Compression=1 must be accepted.
     */
    #[Test]
    public function acceptIfd0CompressionUncompressed(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTags([
                [ExifTag::IMAGE_WIDTH, 100],
                [ExifTag::IMAGE_LENGTH, 100],
                [ExifTag::COMPRESSION, 1],
            ]),
        );

        self::assertSame(Compression::Uncompressed, $result->compression());
    }

    /**
     * IFD1 Compression=6 must be accepted for thumbnails.
     */
    #[Test]
    public function acceptIfd1CompressionJpeg(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [[ExifTag::IMAGE_WIDTH, 100], [ExifTag::IMAGE_LENGTH, 100]],
                [[ExifTag::COMPRESSION, 6], [TiffTag::JPEG_PROC, 1]],
            ),
        );

        self::assertSame(6, $result->ifd1?->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * IFD1 Compression=7 (JPEG new-style TN2) is tolerated (Postel's Law).
     */
    #[Test]
    public function toleratesCompression7InIfd1(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [[ExifTag::IMAGE_WIDTH, 100], [ExifTag::IMAGE_LENGTH, 100]],
                [[ExifTag::COMPRESSION, 7]],
            ),
        );

        self::assertSame(7, $result->ifd1?->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * IFD1 Compression=1 is allowed when IFD0 is uncompressed RGB.
     */
    #[Test]
    public function acceptIfd1CompressionUncompressedForUncompressedRgbPrimary(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [
                    [ExifTag::IMAGE_WIDTH, 100],
                    [ExifTag::IMAGE_LENGTH, 100],
                    [ExifTag::COMPRESSION, 1],
                    [ExifTag::PHOTOMETRIC_INTERPRETATION, 2],
                ],
                [[ExifTag::COMPRESSION, 1]],
            ),
        );

        self::assertSame(1, $result->ifd1?->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * JPEG thumbnail compression is forbidden when IFD0 is uncompressed RGB.
     */
    #[Test]
    public function rejectIfd1JpegCompressionForUncompressedRgbPrimary(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1468);
        $this->expectExceptionMessageMatches('/Table 3|uncompressed RGB|thumbnail/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [
                    [ExifTag::IMAGE_WIDTH, 100],
                    [ExifTag::IMAGE_LENGTH, 100],
                    [ExifTag::COMPRESSION, 1],
                    [ExifTag::PHOTOMETRIC_INTERPRETATION, 2],
                ],
                [[ExifTag::COMPRESSION, 6], [TiffTag::JPEG_PROC, 1]],
            ),
        );
    }

    /**
     * JPEG thumbnail compression is forbidden when IFD0 is uncompressed YCbCr.
     */
    #[Test]
    public function rejectIfd1JpegCompressionForUncompressedYcbcrPrimary(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1468);
        $this->expectExceptionMessageMatches('/Table 3|uncompressed YCbCr|thumbnail/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [
                    [ExifTag::IMAGE_WIDTH, 100],
                    [ExifTag::IMAGE_LENGTH, 100],
                    [ExifTag::COMPRESSION, 1],
                    [ExifTag::PHOTOMETRIC_INTERPRETATION, 6],
                ],
                [[ExifTag::COMPRESSION, 6], [TiffTag::JPEG_PROC, 1]],
            ),
        );
    }

    /**
     * JPEG thumbnail compression remains allowed in JPEG-context EXIF parsing.
     */
    #[Test]
    public function acceptIfd1JpegCompressionInJpegContext(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [[ExifTag::ORIENTATION, 1]],
                [[ExifTag::COMPRESSION, 6], [TiffTag::JPEG_PROC, 1]],
            ),
            jpegContext: true,
        );

        self::assertSame(6, $result->ifd1?->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * JPEGInterchangeFormat shall not be present in IFD0 for JPEG primary context.
     */
    #[Test]
    public function rejectIfd0JpegInterchangeFormatInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1353);
        $this->expectExceptionMessageMatches('/JPEGInterchangeFormat.*IFD0|IFD0.*JPEGInterchangeFormat/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithIfd0Pointer(
                ExifTag::JPEG_INTERCHANGE_FORMAT,
                TiffConst::TYPE_LONG,
                1,
            ),
            jpegContext: true,
        );
    }

    /**
     * JPEGInterchangeFormatLength shall not be present in IFD0 for JPEG primary context.
     */
    #[Test]
    public function rejectIfd0JpegInterchangeFormatLengthInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches(
            '/JPEGInterchangeFormatLength.*IFD0|IFD0.*JPEGInterchangeFormatLength|JPEGInterchangeFormatLength requires JPEGInterchangeFormat/i',
        );

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithIfd0Pointer(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                TiffConst::TYPE_LONG,
                1,
            ),
            jpegContext: true,
        );
    }

    /**
     * Accepts a valid SOI..EOI JPEG thumbnail stream referenced by IFD1 tags.
     */
    #[Test]
    public function acceptValidIfd1JpegThumbnailStream(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\xFF\xDB\x00\x04\x00\x00"
            . "\xFF\xD9";

        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );

        self::assertSame(Compression::Jpeg, $result->thumbnailCompression());
    }

    /**
     * Rejects thumbnail streams with missing SOI or missing EOI.
     */
    #[Test]
    #[DataProvider('provideInvalidThumbnailBoundaryStreams')]
    public function rejectIfd1JpegThumbnailMissingSoiOrEoi(string $thumbnailStream, string $expectedMessage): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );
    }

    /**
     * Accepts APPn markers in JPEG thumbnail streams (Postel's Law).
     */
    #[Test]
    public function acceptIfd1JpegThumbnailWithAppMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\xFF\xE1\x00\x04\x00\x00"
            . "\xFF\xD9";

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts COM markers in JPEG thumbnail streams (Postel's Law).
     */
    #[Test]
    public function acceptIfd1JpegThumbnailWithComMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\xFF\xFE\x00\x04\x00\x00"
            . "\xFF\xD9";

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts restart markers in JPEG thumbnail streams (Postel's Law).
     */
    #[Test]
    public function acceptIfd1JpegThumbnailWithRestartMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\x11\x22\xFF\xD0\x33\x44"
            . "\xFF\xD9";

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * IFD1 Compression=3 (reserved) must be rejected per EXIF 3.0.
     */
    #[Test]
    public function rejectIfd1CompressionReserved(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression value 3 in IFD1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTwoIfds(
                [[ExifTag::IMAGE_WIDTH, 100], [ExifTag::IMAGE_LENGTH, 100]],
                [[ExifTag::COMPRESSION, 3]],
            ),
        );
    }

    /**
     * Accepts valid EXIF camera-control enum values from closed domains.
     */
    #[Test]
    #[DataProvider('provideValidCameraControlEnumValues')]
    public function acceptValidCameraControlEnumDomains(int $tag, int $value): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithExifShortTag($tag, $value),
        );

        self::assertSame($value, $result->exifIfd?->get($tag)?->value);
    }

    /**
     * Out-of-domain EXIF camera-control enum values are tolerated.
     */
    #[Test]
    #[DataProvider('provideInvalidCameraControlEnumValues')]
    public function toleratesOutOfDomainCameraControlEnumValues(int $tag, int $value): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithExifShortTag($tag, $value),
        );

        self::assertSame($value, $result->exifIfd?->get($tag)?->value);
    }

    /**
     * Leaves missing optional camera-control tags accepted.
     */
    #[Test]
    public function acceptMissingCameraControlEnumTags(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTags([
                [ExifTag::IMAGE_WIDTH, 100],
                [ExifTag::IMAGE_LENGTH, 100],
            ]),
        );

        self::assertNull($result->exifIfd);
    }

    /**
     * Accepts valid Flash bitfield combinations and exposes typed flash details.
     *
     * @param int                $flashValue       Raw EXIF Flash SHORT value.
     * @param bool               $fired            Decoded fired bit.
     * @param FlashReturn|null   $returnDetection  Decoded return detection state.
     * @param FlashMode|null     $mode             Decoded flash mode.
     * @param FlashFunction|null $functionPresence Decoded flash function flag.
     * @param bool               $redEyeReduction  Decoded red-eye bit.
     */
    #[Test]
    #[DataProvider('provideValidFlashBitfields')]
    public function acceptValidFlashBitfields(
        int $flashValue,
        bool $fired,
        ?FlashReturn $returnDetection,
        ?FlashMode $mode,
        ?FlashFunction $functionPresence,
        bool $redEyeReduction,
    ): void {
        $result    = (new TiffExifParser())->parseFromBlob($this->buildTiffWithExifShortTag(ExifTag::FLASH, $flashValue));
        $flashInfo = $result->flashInfo();

        self::assertSame($flashValue, $result->flash());
        self::assertNotNull($flashInfo);
        self::assertSame($fired, $flashInfo->fired);
        self::assertSame($returnDetection, $flashInfo->returnDetection);
        self::assertSame($mode, $flashInfo->mode);
        self::assertSame($functionPresence, $flashInfo->functionPresence);
        self::assertSame($redEyeReduction, $flashInfo->redEyeReduction);
    }

    /**
     * Rejects reserved/invalid Flash bitfield combinations per EXIF 3.0 §4.6.6.7.21.
     *
     * @param int $flashValue Raw EXIF Flash SHORT value.
     * @param int $errorCode  Expected ParseError code.
     */
    #[Test]
    #[DataProvider('provideInvalidFlashBitfields')]
    public function rejectInvalidFlashBitfields(int $flashValue, int $errorCode): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode($errorCode);
        $this->expectExceptionMessageMatches('/Flash value .* EXIF 3\\.0 §4\\.6\\.6\\.7\\.21|Flash value .*flash-fired bit is unset/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithExifShortTag(ExifTag::FLASH, $flashValue),
        );
    }

    /**
     * Flash value 0x02 (reserved return-status bits) is tolerated (Postel's Law).
     */
    #[Test]
    public function toleratesReservedFlashReturnStatusBits(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithExifShortTag(ExifTag::FLASH, 0x02),
        );

        self::assertSame(0x02, $result->flash());
    }

    /**
     * @return iterable<string, array{0:int, 1:bool, 2:FlashReturn|null, 3:FlashMode|null, 4:FlashFunction|null, 5:bool}>
     */
    public static function provideValidFlashBitfields(): iterable
    {
        yield 'no flash fired' => [0x00, false, FlashReturn::NoStrobeDetection, FlashMode::Unknown, FlashFunction::Present, false];
        yield 'fired, no return detection, mode auto, red-eye off' => [0x19, true, FlashReturn::NoStrobeDetection, FlashMode::Auto, FlashFunction::Present, false];
        yield 'fired, return detected, mode unknown, function absent, red-eye on' => [0x67, true, FlashReturn::ReturnDetected, FlashMode::Unknown, FlashFunction::Absent, true];
        yield 'fired, return not detected, mode auto, function present, red-eye on' => [0x5D, true, FlashReturn::ReturnNotDetected, FlashMode::Auto, FlashFunction::Present, true];
    }

    /**
     * @return iterable<string, array{0:int, 1:int}>
     */
    public static function provideInvalidFlashBitfields(): iterable
    {
        yield 'return-not-detected without fired bit' => [0x04, 1419];
        yield 'return-detected without fired bit' => [0x06, 1419];
    }

    /**
     * Accepts CompositeImage values 0..2 without requiring companion tags.
     *
     * @param int $compositeImageValue CompositeImage tag value in the non-captured range.
     */
    #[Test]
    #[DataProvider('provideCompositeImageValuesWithoutCompanionRequirements')]
    public function acceptCompositeImageValuesWithoutCompanionRequirements(int $compositeImageValue): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags($compositeImageValue, null, null),
        );

        self::assertSame($compositeImageValue, $result->compositeImage()?->value);
        self::assertNull($result->sourceImageNumberOfCompositeImage());
        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * Accepts CompositeImage value 3 when both companion tags are present and valid.
     */
    #[Test]
    public function acceptCompositeImageCapturedWithRequiredCompanionTags(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(
                3,
                [5, 3],
                $this->buildValidCompositeExposurePayload(),
            ),
        );

        $exposureTimes = $result->sourceExposureTimesOfCompositeImage();

        self::assertSame(3, $result->compositeImage()?->value);
        self::assertSame([5, 3], $result->sourceImageNumberOfCompositeImage());
        self::assertNotNull($exposureTimes);
        self::assertSame(5.0, $exposureTimes->totalExposurePeriod);
        self::assertSame([[0.1, 0.2]], $exposureTimes->sequences);
    }

    /**
     * Rejects reserved CompositeImage values outside 0..3.
     */
    #[Test]
    public function rejectReservedCompositeImageValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1420);
        $this->expectExceptionMessage('CompositeImage value 4 is outside the valid domain');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(4, null, null),
        );
    }

    /**
     * Tolerates CompositeImage=3 when SourceImageNumberOfCompositeImage tag is absent.
     * The CompositeImage value is preserved; the missing companion is not fatal.
     */
    #[Test]
    public function itToleratesCompositeImageWithoutSourceImageNumber(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(3, null, $this->buildValidCompositeExposurePayload()),
        );

        self::assertSame(3, $result->compositeImage()?->value);
        self::assertNull($result->sourceImageNumberOfCompositeImage());
    }

    /**
     * Tolerates CompositeImage=3 when SourceExposureTimesOfCompositeImage tag is absent.
     * The CompositeImage value is preserved; the missing companion is not fatal.
     */
    #[Test]
    public function itToleratesCompositeImageWithoutSourceExposureTimesTag(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(3, [5, 3], null),
        );

        self::assertSame(3, $result->compositeImage()?->value);
        self::assertSame([5, 3], $result->sourceImageNumberOfCompositeImage());
        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * Tolerates CompositeImage=3 when SourceImageNumberOfCompositeImage payload is invalid.
     * The CompositeImage value is preserved; the malformed companion is skipped.
     */
    #[Test]
    public function itToleratesInvalidSourceImageNumberPayload(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(3, [1, 1], $this->buildValidCompositeExposurePayload()),
        );

        self::assertSame(3, $result->compositeImage()?->value);
        self::assertNull($result->sourceImageNumberOfCompositeImage());
    }

    /**
     * Tolerates CompositeImage=3 when SourceExposureTimesOfCompositeImage payload is invalid.
     * The CompositeImage value is preserved; the malformed companion is skipped.
     */
    #[Test]
    public function itToleratesInvalidSourceExposurePayload(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(3, [5, 3], "\x01\x00"),
        );

        self::assertSame(3, $result->compositeImage()?->value);
        self::assertSame([5, 3], $result->sourceImageNumberOfCompositeImage());
        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * Accepts a valid SourceExposureTimesOfCompositeImage payload unchanged.
     */
    #[Test]
    public function acceptValidSourceExposureTimesPayload(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(0, null, $this->buildValidCompositeExposurePayload()),
        );

        $exposureTimes = $result->sourceExposureTimesOfCompositeImage();

        self::assertNotNull($exposureTimes);
        self::assertSame(5.0, $exposureTimes->totalExposurePeriod);
        self::assertSame([[0.1, 0.2]], $exposureTimes->sequences);
    }

    /**
     * Tolerates truncated SourceExposureTimesOfCompositeImage summary section.
     * The malformed payload decodes to null instead of aborting the parse.
     */
    #[Test]
    public function itToleratesTruncatedSourceExposureTimesSummary(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(0, null, substr($this->buildCompositeExposureSummaryBytes(), 0, 60)),
        );

        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * Tolerates SourceExposureTimesOfCompositeImage payload truncated in sequence records.
     * The malformed payload decodes to null instead of aborting the parse.
     */
    #[Test]
    public function itToleratesTruncatedSourceExposureTimesSequenceSection(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(0, null, $this->buildCompositeExposurePayloadWithTruncatedSequenceSection()),
        );

        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * Tolerates SourceExposureTimesOfCompositeImage payload with inconsistent counts.
     * The malformed payload decodes to null instead of aborting the parse.
     */
    #[Test]
    public function itToleratesSourceExposureTimesWithInconsistentCounts(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCompositeExifTags(0, null, $this->buildCompositeExposurePayloadWithInconsistentCounts()),
        );

        self::assertNull($result->sourceExposureTimesOfCompositeImage());
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function provideCompositeImageValuesWithoutCompanionRequirements(): iterable
    {
        yield 'not composite' => [0];
        yield 'general composite' => [1];
        yield 'composite image created by processing' => [2];
    }

    /**
     * @return iterable<string, array{0:int, 1:int}>
     */
    public static function provideValidCameraControlEnumValues(): iterable
    {
        yield 'ExposureProgram:8' => [ExifTag::EXPOSURE_PROGRAM, 8];
        yield 'MeteringMode:255' => [ExifTag::METERING_MODE, 255];
        yield 'LightSource:24' => [ExifTag::LIGHT_SOURCE, 24];
        yield 'SensingMethod:7' => [ExifTag::SENSING_METHOD, 7];
        yield 'ExposureMode:2' => [ExifTag::EXPOSURE_MODE, 2];
        yield 'WhiteBalance:1' => [ExifTag::WHITE_BALANCE, 1];
        yield 'SceneCaptureType:3' => [ExifTag::SCENE_CAPTURE_TYPE, 3];
        yield 'GainControl:4' => [ExifTag::GAIN_CONTROL, 4];
        yield 'Contrast:2' => [ExifTag::CONTRAST, 2];
        yield 'Saturation:2' => [ExifTag::SATURATION, 2];
        yield 'Sharpness:2' => [ExifTag::SHARPNESS, 2];
        yield 'SubjectDistanceRange:3' => [ExifTag::SUBJECT_DISTANCE_RANGE, 3];
    }

    /**
     * @return iterable<string, array{0:int, 1:int}>
     */
    public static function provideInvalidCameraControlEnumValues(): iterable
    {
        yield 'ExposureProgram:9' => [ExifTag::EXPOSURE_PROGRAM, 9];
        yield 'MeteringMode:7' => [ExifTag::METERING_MODE, 7];
        yield 'LightSource:8' => [ExifTag::LIGHT_SOURCE, 8];
        yield 'SensingMethod:6' => [ExifTag::SENSING_METHOD, 6];
        yield 'ExposureMode:3' => [ExifTag::EXPOSURE_MODE, 3];
        yield 'WhiteBalance:2' => [ExifTag::WHITE_BALANCE, 2];
        yield 'SceneCaptureType:4' => [ExifTag::SCENE_CAPTURE_TYPE, 4];
        yield 'GainControl:5' => [ExifTag::GAIN_CONTROL, 5];
        yield 'Contrast:3' => [ExifTag::CONTRAST, 3];
        yield 'Saturation:3' => [ExifTag::SATURATION, 3];
        yield 'Sharpness:3' => [ExifTag::SHARPNESS, 3];
        yield 'SubjectDistanceRange:4' => [ExifTag::SUBJECT_DISTANCE_RANGE, 4];
    }

    /**
     * In JPEG context, StripOffsets in IFD0 is tolerated.
     * Real-world cameras include StripOffsets even in JPEG-compressed primaries.
     */
    #[Test]
    public function itToleratesStripOffsetsInIfd0JpegContext(): void
    {
        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::STRIP_OFFSETS, 0),
            jpegContext: true,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * In JPEG context, YCbCrSubSampling in IFD0 is tolerated.
     * Real-world cameras include YCbCrSubSampling even in JPEG-compressed primaries.
     */
    #[Test]
    public function itToleratesYcbcrSubSamplingInIfd0JpegContext(): void
    {
        // YCbCrSubSampling is SHORT[2]
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::YCBCR_SUB_SAMPLING)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 2)
            . pack('v', 2) . pack('v', 2)
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);

        $this->addToAssertionCount(1);
    }

    /**
     * Without JPEG context, prohibited tags parse normally.
     */
    #[Test]
    public function acceptBitsPerSampleOutsideJpegContext(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTags([
                [ExifTag::IMAGE_WIDTH, 100],
                [ExifTag::IMAGE_LENGTH, 100],
                [ExifTag::BITS_PER_SAMPLE, 8],
            ]),
        );

        self::assertSame(8, $result->ifd0->get(ExifTag::BITS_PER_SAMPLE)?->value);
    }

    /**
     * ImageWidth with value 0 must be rejected for non-JPEG images.
     */
    #[Test]
    public function rejectZeroImageWidth(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1355);
        $this->expectExceptionMessage('ImageWidth value 0 is invalid');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTags([
                [ExifTag::IMAGE_WIDTH, 0],
                [ExifTag::IMAGE_LENGTH, 100],
            ]),
        );
    }

    /**
     * JPEG context skips ImageWidth/ImageLength validation.
     */
    #[Test]
    public function acceptMissingDimensionsInJpegContext(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::YCBCR_POSITIONING, 1),
            jpegContext: true,
        );

        self::assertNull($result->ifd0->get(ExifTag::IMAGE_WIDTH));
        self::assertNull($result->ifd0->get(ExifTag::IMAGE_LENGTH));
    }

    /**
     * Duplicate tag IDs within a single IFD are tolerated — the first occurrence wins.
     */
    #[Test]
    public function toleratesDuplicateTagIdInIfd(): void
    {
        // Build a TIFF with two entries having the same tag ID (0x0100 = ImageWidth)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)          // First IFD at offset 8
            . pack('v', 2)          // 2 entries in IFD
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_WIDTH)  // Duplicate tag ID
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 200) . pack('v', 0)
            . pack('V', 0);         // Next IFD offset

        $reader = new TiffExifParser();
        $parsed = $reader->parseFromBlob($blob);

        // First occurrence (value=100) wins
        $entry = $parsed->ifd0->get(ExifTag::IMAGE_WIDTH);
        self::assertNotNull($entry);
        self::assertSame(100, $entry->value);
    }

    /**
     * A BigTIFF IFD entry whose count × component-size overflows PHP integer
     * range must raise ParseError (not TypeError from float coercion).
     */
    #[Test]
    public function rejectsOverflowingCountTimesComponentSize(): void
    {
        // BigTIFF header: II + magic 0x002B + offsetSize 8 + reserved 0 + first IFD offset (16)
        $header = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        // BigTIFF IFD: 8-byte entry count + entries (20 bytes each) + 8-byte next-IFD
        // One entry: tag(2) + type(2) + count(8) + value/offset(8)
        $hugeCount = (int) (PHP_INT_MAX / 4) + 1; // overflow when × 4 (TYPE_LONG)
        $ifd       = pack('P', 1)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('P', $hugeCount)
            . pack('P', 0)
            . pack('P', 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1339);

        (new TiffExifParser())->parseFromBlob($header . $ifd, jpegContext: true);
    }
}
