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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
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
     * Verifies the parser rejects the header with a BoundsError.
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

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates an IFD chain where the next pointer loops back to the same IFD.
     * Confirms the parser detects the cycle and stops without looping indefinitely.
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
            . pack('v', 0)           // 0 entries in IFD
            . pack('V', $ifdOffset); // Next IFD points back to offset 8 (cycle)

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);

        // Parser should detect the cycle and stop (not infinite loop)
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
        // TIFF header
        $blob = 'II'  // Little-endian
            . pack('v', TiffConst::MAGIC_CLASSIC)  // Classic TIFF magic
            . pack('V', 8);  // First IFD at offset 8

        // IFD0 with 1 entry (XResolution as RATIONAL)
        $blob .= pack('v', 1);  // Entry count

        // Entry: Tag=0x011A (XResolution), Type=RATIONAL(5), Count=1, Value/Offset=26
        $blob .= pack('v', 0x011A)       // Tag
            . pack('v', TiffConst::TYPE_RATIONAL)  // Type
            . pack('V', 1)               // Count
            . pack('V', 26);             // Offset to rational data

        // Next IFD offset (none)
        $blob .= pack('V', 0);

        // Rational data at offset 26: numerator and denominator
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
        // TIFF header
        $blob = 'II'  // Little-endian
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        // IFD0 with 1 entry (ShutterSpeedValue as SRATIONAL)
        $blob .= pack('v', 1);

        // Entry: Tag=0x9201 (ShutterSpeedValue), Type=SRATIONAL(10), Count=1, Offset=26
        $blob .= pack('v', 0x9201)
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', 1)
            . pack('V', 26);

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

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            default                   => 1,
        };
    }
}
