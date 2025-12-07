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
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Negative tests for TiffExifReader focusing on malformed and corrupted TIFF/EXIF data.
 *
 * These tests verify that the parser correctly rejects invalid inputs and throws
 * appropriate exceptions (ParseError or BoundsError) rather than crashing or
 * producing incorrect results.
 *
 * EXIF 3.0 §4.5 and TIFF 6.0 §2 define the structure that these tests deliberately violate.
 */
#[CoversClass(TiffExifReader::class)]
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
final class TiffExifReaderNegativeTest extends TestCase
{
    /**
     * Tests that an invalid byte order marker triggers a ParseError.
     *
     * EXIF 3.0 §4.5.1 specifies that only "II" (little-endian) and "MM" (big-endian)
     * are valid byte order markers.
     */
    #[Test]
    public function rejectsInvalidByteOrderMarker(): void
    {
        $blob = 'XX' . pack('n', TiffConst::MAGIC_CLASSIC) . pack('N', 8);

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Bad TIFF byte order');

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that an invalid magic number triggers a ParseError.
     *
     * EXIF 3.0 §4.5.1 recognizes only 0x002A (classic TIFF) and 0x002B (BigTIFF).
     */
    #[Test]
    public function rejectsInvalidMagicNumber(): void
    {
        $blob = 'II' . pack('v', 0x9999) . pack('V', 8);

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unknown TIFF magic');

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that an IFD offset pointing beyond the blob size triggers a BoundsError.
     *
     * EXIF 3.0 §4.5.2 requires that IFD offsets point to valid locations within
     * the TIFF blob.
     */
    #[Test]
    public function rejectsIfdOffsetBeyondBlobSize(): void
    {
        // Classic TIFF with first IFD offset pointing way beyond blob
        $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 0xFFFFFF);

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that truncated TIFF data (missing IFD) triggers an error.
     *
     * EXIF 3.0 §4.5.2 defines the IFD structure which must be complete.
     */
    #[Test]
    public function rejectsTruncatedIfdHeader(): void
    {
        // Header points to offset 8, but no data there
        $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 8);

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that a BigTIFF header with invalid offset size triggers a ParseError.
     *
     * EXIF 3.0 §4.5.1 restricts BigTIFF offset sizes to 8 or 16 bytes.
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

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported BigTIFF offset size');

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that a BigTIFF header with non-zero reserved field triggers a ParseError.
     *
     * EXIF 3.0 §4.5.1 requires the reserved field to be zero.
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

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Bad BigTIFF header');

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests handling of RATIONAL with zero denominator.
     *
     * EXIF 3.0 §4.5.2 defines RATIONAL as two LONGs (numerator/denominator).
     * Division by zero is a special case that should be handled gracefully.
     */
    #[Test]
    public function handlesRationalWithZeroDenominator(): void
    {
        // Create a minimal valid TIFF with one IFD entry containing a RATIONAL with denominator = 0
        $blob = $this->buildMinimalTiffWithRational(100, 0);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        // Should parse without throwing, but denominator will be 0
    }

    /**
     * Tests handling of SRATIONAL with extreme values.
     *
     * EXIF 3.0 §4.5.2 defines SRATIONAL as two signed LONGs.
     */
    #[Test]
    public function handlesSrationalWithExtremeValues(): void
    {
        // SRATIONAL with INT_MIN and INT_MAX
        $blob = $this->buildMinimalTiffWithSRational(-2147483648, 2147483647);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);
    }

    /**
     * Tests that an IFD entry count causing overflow is rejected.
     *
     * EXIF 3.0 §4.5.2 specifies the IFD entry count format.
     */
    #[Test]
    public function rejectsIfdWithHugeEntryCount(): void
    {
        // Classic TIFF with IFD at offset 8, claiming 65535 entries (would overflow)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)      // First IFD offset
            . pack('v', 65535); // Huge entry count

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests detection of cyclic IFD chains (IFD pointing back to itself).
     *
     * EXIF 3.0 §4.5.2 describes IFD chaining via nextIfdOffset but doesn't
     * allow cycles.
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

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        // Parser should detect the cycle and stop (not infinite loop)
    }

    /**
     * Tests that unsupported TIFF type codes are rejected.
     *
     * EXIF 3.0 §4.5.2 Table 3 lists the valid TIFF types.
     */
    #[Test]
    public function rejectsUnsupportedTiffType(): void
    {
        // Build TIFF with an entry using invalid type code (99)
        $blob = $this->buildTiffWithInvalidType(99);

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests truncated IFD entry data.
     *
     * EXIF 3.0 §4.5.2 requires complete IFD entries.
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

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a minimal valid TIFF blob with a RATIONAL value.
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
     * Builds a minimal valid TIFF blob with an SRATIONAL value.
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
}
