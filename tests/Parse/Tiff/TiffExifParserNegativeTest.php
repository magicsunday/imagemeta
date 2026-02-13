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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

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
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(Endian::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoundsError::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(Compression::class)]
final class TiffExifParserNegativeTest extends TestCase
{
    /**
     * Uses a bogus byte-order marker instead of II/MM.
     * Confirms the parser raises ParseError for an invalid byte order value.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * Creates an IFD chain where the next pointer loops back to the same IFD.
     * Confirms the parser detects the cycle and rejects it with ParseError.
     *
     * @return void
     */
    #[Test]
    public function detectsCyclicIfdChain(): void
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

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a TIFF entry using an invalid field type code.
     * Ensures the parser rejects unsupported TIFF types with ParseError.
     *
     * @return void
     */
    #[Test]
    public function rejectsUnsupportedTiffType(): void
    {
        // Build TIFF with an entry using invalid type code (99)
        $blob = $this->buildTiffWithInvalidType(99);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Truncates the IFD entry so mandatory fields are missing.
     * Verifies a BoundsError is thrown for the incomplete entry data.
     *
     * @return void
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
     * Uses an ASCII entry whose declared payload omits the required trailing NUL.
     * Ensures the parser rejects non-conformant ASCII values.
     *
     * @return void
     */
    #[Test]
    public function rejectsAsciiValueWithoutNullTerminator(): void
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

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ASCII values must be NUL-terminated and include the terminator in count per EXIF 3.0 §4.6.2; TIFF 6.0 §2.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds an IFD with descending tag identifiers.
     * Verifies the parser rejects unsorted directory entries per TIFF 6.0.
     *
     * @return void
     */
    #[Test]
    public function rejectsIfdEntriesThatAreNotSortedByTag(): void
    {
        // Use Software (0x0131) before Artist (0x013B) — wrong order: 0x0131 < 0x013B is correct,
        // so reverse them: Artist (0x013B) first, then Software (0x0131) — descending = invalid.
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

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD entries must be sorted in ascending order by tag per TIFF 6.0 §2.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates an interoperability pointer entry with an invalid type/count layout.
     * Ensures the parser throws a ParseError with the expected validation message.
     *
     * @return void
     */
    #[Test]
    public function rejectsInteropPointerWithInvalidLayout(): void
    {
        $blob = $this->buildTiffWithInteropPointer(TiffConst::TYPE_SHORT, 2);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD pointer tag 0xA005 must contain exactly one offset per EXIF 3.0 §4.6.3.3.1.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates an ExifIFDPointer entry with count=2 instead of the required 1.
     * Ensures the parser rejects bad ExifIFD pointer count per EXIF 3.0 §4.6.3.1.1.
     *
     * @return void
     */
    #[Test]
    public function rejectsExifIfdPointerWithBadCount(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::EXIF_IFD_POINTER, TiffConst::TYPE_LONG, 2);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x8769 must contain exactly one offset per EXIF 3.0 §4.6.3.1.1.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates an ExifIFDPointer entry with type SHORT instead of LONG.
     * Ensures the parser rejects bad ExifIFD pointer type per EXIF 3.0 §4.6.3.1.1.
     *
     * @return void
     */
    #[Test]
    public function rejectsExifIfdPointerWithBadType(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::EXIF_IFD_POINTER, TiffConst::TYPE_SHORT, 1);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x8769 must use a LONG/IFD field type per EXIF 3.0 §4.6.3.1.1.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates a GPSInfoIFDPointer entry with count=3 instead of the required 1.
     * Ensures the parser rejects bad GPS pointer count per EXIF 3.0 §4.6.3.2.1.
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsIfdPointerWithBadCount(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::GPS_IFD_POINTER, TiffConst::TYPE_LONG, 3);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x8825 must contain exactly one offset per EXIF 3.0 §4.6.3.2.1.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates a GPSInfoIFDPointer entry with type ASCII instead of LONG.
     * Ensures the parser rejects bad GPS pointer type per EXIF 3.0 §4.6.3.2.1.
     *
     * @return void
     */
    #[Test]
    public function rejectsGpsIfdPointerWithBadType(): void
    {
        $blob = $this->buildTiffWithIfd0Pointer(ExifTag::GPS_IFD_POINTER, TiffConst::TYPE_ASCII, 1);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD pointer tag 0x8825 must use a LONG/IFD field type per EXIF 3.0 §4.6.3.2.1.');

        $reader->parseFromBlob($blob);
    }

    /**
     * Feeds fixed-length tags with invalid counts via a data provider.
     * Confirms the parser rejects each case with the expected ParseError message.
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function rejectsLong8InClassicTiff(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('BigTIFF-only field type');

        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_LONG8, 1);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a classic TIFF with an SLONG8 field type in IFD0.
     * Confirms the parser rejects BigTIFF-only types in classic TIFF.
     *
     * @return void
     */
    #[Test]
    public function rejectsSlong8InClassicTiff(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('BigTIFF-only field type');

        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_SLONG8, 1);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a classic TIFF with an IFD8 field type in IFD0.
     * Confirms the parser rejects BigTIFF-only types in classic TIFF.
     *
     * @return void
     */
    #[Test]
    public function rejectsIfd8InClassicTiff(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('BigTIFF-only field type');

        $blob = $this->buildTiffWithIfd0Pointer(0x0100, TiffConst::TYPE_IFD8, 1);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);
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
     * Builds a TIFF blob with an Exif IFD that carries a malformed interoperability pointer.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $type  Field type used for the interoperability pointer entry.
     * @param int $count Value count stored for the interoperability pointer entry.
     */
    private function buildTiffWithInteropPointer(int $type, int $count): string
    {
        $ifd0Offset    = 8;
        $exifIfdOffset = 26;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        // IFD0 with an Exif IFD pointer
        $blob .= pack('v', 1)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        // Exif IFD with malformed interoperability pointer entry
        $blob .= pack('v', 1)
            . pack('v', ExifTag::INTEROPERABILITY_IFD_POINTER)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', 0)
            . pack('V', 0);

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
            'ExifVersion expects 4 UNDEFINED bytes' => [
                ExifTag::EXIF_VERSION,
                TiffConst::TYPE_UNDEFINED,
                3,
                '010',
                'ExifVersion must contain exactly 4 bytes per EXIF 3.0 §4.6.6.1.1.',
            ],
            'ExifVersion rejects SHORT type' => [
                ExifTag::EXIF_VERSION,
                TiffConst::TYPE_SHORT,
                4,
                "\x03\x00\x00\x00\x00\x00\x00\x00",
                'ExifVersion must use TIFF type UNDEFINED per EXIF 3.0 §4.6.6.1.1.',
            ],
            'FlashpixVersion expects 4 UNDEFINED bytes' => [
                ExifTag::FLASHPIX_VERSION,
                TiffConst::TYPE_UNDEFINED,
                3,
                '010',
                'FlashpixVersion must contain exactly 4 bytes per EXIF 3.0 §4.6.6.1.2.',
            ],
            'ComponentsConfiguration expects 4 bytes' => [
                ExifTag::COMPONENTS_CONFIGURATION,
                TiffConst::TYPE_UNDEFINED,
                3,
                "\x01\x02\x03",
                'ComponentsConfiguration must contain exactly 4 bytes per EXIF 3.0 §4.6.6.1.3.',
            ],
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
     * Differing XResolution and YResolution values are rejected per EXIF 3.0.
     */
    #[Test]
    public function rejectDifferingXAndYResolution(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('XResolution');

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

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * YCbCrPositioning value 3 is rejected per EXIF 3.0 §4.6.5.1.13.
     */
    #[Test]
    public function rejectInvalidYCbCrPositioning(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('YCbCrPositioning value 3 is outside the valid domain {1, 2}');

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
     * ResolutionUnit value 1 is rejected per EXIF 3.0 §4.6.5.1.11.
     */
    #[Test]
    public function rejectInvalidResolutionUnit(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ResolutionUnit value 1 is outside the valid domain {2, 3}');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithShortTag(ExifTag::RESOLUTION_UNIT, 1));
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
     * Orientation value 0 is rejected per EXIF 3.0 §4.6.5.1.6.
     */
    #[Test]
    public function rejectOrientationValueZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Orientation value 0 is outside the valid domain 1..8');

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 0) . pack('v', 0) // value=0 inline
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Orientation value 9 is rejected per EXIF 3.0 §4.6.5.1.6.
     */
    #[Test]
    public function rejectOrientationValueNine(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Orientation value 9 is outside the valid domain 1..8');

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
     * ASCII value containing a byte > 0x7F is rejected per TIFF 6.0 §2.2.
     */
    #[Test]
    public function rejectAsciiValueWithNon7BitByte(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ASCII value contains non-7-bit byte');

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

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Odd first IFD offset is rejected per TIFF 6.0 word-alignment rule.
     */
    #[Test]
    public function rejectOddFirstIfdOffset(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('is not word-aligned');

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 9) // odd offset
            . str_repeat("\0", 32); // padding so bounds check passes first

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * IFD with entryCount=0 is rejected per TIFF 6.0.
     */
    #[Test]
    public function rejectEmptyIfd(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('IFD must contain at least one entry per TIFF 6.0.');

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 0)
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            default                   => 1,
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
        $ifd1EntryCount = 3;
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
            . $this->buildIfdShortEntry(ExifTag::COMPRESSION, Compression::JPEG->value)
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
     * IFD0 Compression=6 must be rejected per EXIF 3.0 §4.6.5.1.4.
     */
    #[Test]
    public function rejectIfd0CompressionJpeg(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression value 6 in IFD0');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::COMPRESSION, 6),
        );
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

        self::assertSame(Compression::UNCOMPRESSED, $result->compression());
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
                [[ExifTag::COMPRESSION, 6]],
            ),
        );

        self::assertSame(6, $result->ifd1?->get(ExifTag::COMPRESSION)?->value);
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

        self::assertSame(Compression::JPEG, $result->thumbnailCompression());
    }

    /**
     * Rejects thumbnail streams with missing SOI or missing EOI.
     *
     * @param string $thumbnailStream
     * @param string $expectedMessage
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
     * Rejects APPn markers in strict JPEG thumbnail validation.
     */
    #[Test]
    public function rejectIfd1JpegThumbnailWithAppMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\xFF\xE1\x00\x04\x00\x00"
            . "\xFF\xD9";

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/thumbnail stream.*APP marker|APP marker.*thumbnail stream/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );
    }

    /**
     * Rejects COM markers in strict JPEG thumbnail validation.
     */
    #[Test]
    public function rejectIfd1JpegThumbnailWithComMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\xFF\xFE\x00\x04\x00\x00"
            . "\xFF\xD9";

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/thumbnail stream.*COM marker|COM marker.*thumbnail stream/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );
    }

    /**
     * Rejects restart markers in strict JPEG thumbnail validation.
     */
    #[Test]
    public function rejectIfd1JpegThumbnailWithRestartMarker(): void
    {
        $thumbnailStream = "\xFF\xD8"
            . "\x11\x22\xFF\xD0\x33\x44"
            . "\xFF\xD9";

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/thumbnail stream.*restart marker|restart marker.*thumbnail stream/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJpegThumbnailStream($thumbnailStream),
        );
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
     *
     * @param int $tag
     * @param int $value
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
     * Rejects reserved/out-of-domain EXIF camera-control enum values.
     *
     * @param int $tag
     * @param int $value
     */
    #[Test]
    #[DataProvider('provideInvalidCameraControlEnumValues')]
    public function rejectInvalidCameraControlEnumDomains(int $tag, int $value): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/reserved or out of domain/i');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithExifShortTag($tag, $value),
        );
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
     * In JPEG context, BitsPerSample shall not be present in IFD0.
     */
    #[Test]
    public function rejectBitsPerSampleInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('BitsPerSample shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::BITS_PER_SAMPLE, 8),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, SamplesPerPixel shall not be present in IFD0.
     */
    #[Test]
    public function rejectSamplesPerPixelInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SamplesPerPixel shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::SAMPLES_PER_PIXEL, 3),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, PhotometricInterpretation shall not be present in IFD0.
     */
    #[Test]
    public function rejectPhotometricInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('PhotometricInterpretation shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::PHOTOMETRIC_INTERPRETATION, 2),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, StripOffsets shall not be present in IFD0.
     */
    #[Test]
    public function rejectStripOffsetsInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripOffsets shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::STRIP_OFFSETS, 0),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, RowsPerStrip shall not be present in IFD0.
     */
    #[Test]
    public function rejectRowsPerStripInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('RowsPerStrip shall not be present');

        // RowsPerStrip is not in FIXED_LENGTH_TAGS, need to use SHORT type directly
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::ROWS_PER_STRIP)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob($blob, jpegContext: true);
    }

    /**
     * In JPEG context, StripByteCounts shall not be present in IFD0.
     */
    #[Test]
    public function rejectStripByteCountsInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripByteCounts shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::STRIP_BYTE_COUNTS, 0),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, PlanarConfiguration shall not be present in IFD0.
     */
    #[Test]
    public function rejectPlanarConfigurationInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('PlanarConfiguration shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::PLANAR_CONFIGURATION, 1),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, Compression shall not be present in IFD0.
     */
    #[Test]
    public function rejectCompressionInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression shall not be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::COMPRESSION, 1),
            jpegContext: true,
        );
    }

    /**
     * In JPEG context, YCbCrSubSampling shall not be present in IFD0.
     */
    #[Test]
    public function rejectYCbCrSubSamplingInJpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('YCbCrSubSampling shall not be present');

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
     * Missing ImageWidth in IFD0 must be rejected for non-JPEG images.
     */
    #[Test]
    public function rejectMissingImageWidth(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1355);
        $this->expectExceptionMessage('ImageWidth tag is required');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::IMAGE_LENGTH, 100),
        );
    }

    /**
     * Missing ImageLength in IFD0 must be rejected for non-JPEG images.
     */
    #[Test]
    public function rejectMissingImageLength(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1356);
        $this->expectExceptionMessage('ImageLength tag is required');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithShortTag(ExifTag::IMAGE_WIDTH, 100),
        );
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
     * Duplicate tag IDs within a single IFD must be rejected per TIFF 6.0 §2.
     */
    #[Test]
    public function rejectsDuplicateTagIdInIfd(): void
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

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1357);
        $this->expectExceptionMessage('Duplicate tag ID');

        $reader->parseFromBlob($blob);
    }
}
