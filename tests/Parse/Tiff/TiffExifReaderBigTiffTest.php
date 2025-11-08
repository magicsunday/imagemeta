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
 * Negative tests for BigTIFF-specific parsing scenarios.
 *
 * BigTIFF extends classic TIFF to support files larger than 4 GiB using 64-bit
 * offsets and counts. These tests verify proper handling of edge cases and
 * invalid BigTIFF structures.
 *
 * EXIF 3.0 §4.5.1 describes BigTIFF support with magic number 0x002B.
 */
#[CoversClass(TiffExifReader::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(Endian::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoundsError::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifReaderBigTiffTest extends TestCase
{
    /**
     * Tests that BigTIFF with offset size 16 is accepted.
     *
     * EXIF 3.0 §4.5.1 allows offset sizes of 8 or 16 bytes.
     */
    #[Test]
    public function acceptsBigTiffWithOffsetSize16(): void
    {
        $blob = $this->buildBigTiffHeader(16, 0, 16);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->ifd0);
    }

    /**
     * Tests rejection of BigTIFF with offset size other than 8 or 16.
     *
     * EXIF 3.0 §4.5.1 restricts offset sizes to 8 or 16.
     */
    #[Test]
    public function rejectsBigTiffWithOffsetSize12(): void
    {
        $blob = $this->buildBigTiffHeader(12, 0, 16);

        $reader = new TiffExifReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported BigTIFF offset size');

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that zero first IFD offset in BigTIFF creates empty IFD.
     *
     * EXIF 3.0 §4.5.2 Note 1 states that a zero pointer indicates an absent directory.
     */
    #[Test]
    public function handlesZeroFirstIfdOffsetInBigTiff(): void
    {
        $blob = $this->buildBigTiffHeader(8, 0, 0);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        // Zero offset should result in empty IFD
        self::assertNotNull($result->ifd0);
        self::assertCount(0, $result->ifd0->entries);
    }

    /**
     * Tests BigTIFF IFD with 64-bit entry count.
     *
     * EXIF 3.0 §4.5.2 specifies that BigTIFF uses 64-bit (8-byte) entry counts
     * instead of 16-bit counts in classic TIFF.
     */
    #[Test]
    public function parsesBigTiffWithLargeEntryCount(): void
    {
        // BigTIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)      // Offset size
            . pack('v', 0)      // Reserved
            . pack('P', 16);    // First IFD at offset 16

        // IFD with 64-bit entry count (2 entries)
        $blob .= pack('P', 2);  // Entry count (64-bit)

        // Entry 1: Manufacturer (ASCII string "Test")
        $blob .= pack('v', 0x010F)               // Tag
            . pack('v', TiffConst::TYPE_ASCII)   // Type
            . pack('P', 5)                       // Count (64-bit): "Test\0"
            . pack('a8', "Test\0");              // Inline value (8 bytes max in BigTIFF)

        // Entry 2: Model (ASCII string "ABC")
        $blob .= pack('v', 0x0110)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('P', 4)
            . pack('a8', "ABC\0");

        // Next IFD offset (none)
        $blob .= pack('P', 0);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->ifd0);
        self::assertCount(2, $result->ifd0->entries);
    }

    /**
     * Tests BigTIFF with LONG8 (64-bit unsigned integer) type.
     *
     * EXIF 3.0 §4.5.2 Table 3 defines TYPE_LONG8 (16) for BigTIFF.
     */
    #[Test]
    public function parsesBigTiffWithLong8Type(): void
    {
        // BigTIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        // IFD with LONG8 entry
        $blob .= pack('P', 1);  // 1 entry

        // Entry: ImageWidth as LONG8
        $blob .= pack('v', 0x0100)               // Tag: ImageWidth
            . pack('v', TiffConst::TYPE_LONG8)   // Type: LONG8
            . pack('P', 1)                       // Count: 1
            . pack('P', 1920);                   // Inline value: 1920 (fits in 8 bytes)

        $blob .= pack('P', 0);  // Next IFD

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->ifd0);
        self::assertCount(1, $result->ifd0->entries);
    }

    /**
     * Tests BigTIFF with SLONG8 (64-bit signed integer) type.
     *
     * EXIF 3.0 §4.5.2 Table 3 defines TYPE_SLONG8 (17) for BigTIFF.
     */
    #[Test]
    public function parsesBigTiffWithSlong8Type(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 1);  // 1 entry

        // Entry with SLONG8 (signed 64-bit value: -42)
        $blob .= pack('v', 0x8769)                // Tag: ExifIFDPointer (just as example)
            . pack('v', TiffConst::TYPE_SLONG8)   // Type: SLONG8
            . pack('P', 1)                        // Count
            . pack('q', -42);                     // Inline signed value

        $blob .= pack('P', 0);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->ifd0);
    }

    /**
     * Tests BigTIFF with IFD8 pointer type.
     *
     * EXIF 3.0 §4.5.2 Table 3 defines TYPE_IFD8 (18) as 64-bit IFD offset.
     */
    #[Test]
    public function parsesBigTiffWithIfd8PointerType(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 1);

        // Entry: SubIFDs pointer using IFD8 type pointing to offset 0 (no sub-IFD)
        $blob .= pack('v', 0x014A)              // Tag: SubIFDs
            . pack('v', TiffConst::TYPE_IFD8)   // Type: IFD8
            . pack('P', 1)                      // Count
            . pack('P', 0);                     // Pointer: 0 (no sub-IFD)

        $blob .= pack('P', 0);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->ifd0);
    }

    /**
     * Tests that BigTIFF offset pointing beyond blob size triggers BoundsError.
     *
     * EXIF 3.0 §4.5.2 requires offsets to be within the file.
     */
    #[Test]
    public function rejectsBigTiffOffsetBeyondBlob(): void
    {
        // First IFD offset points to 0xFFFFFFFFFFFFFFFF (way beyond any real file)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 0xFFFFFFFFFFFF);  // Huge offset

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests BigTIFF with entry value offset beyond 4 GiB boundary.
     *
     * BigTIFF allows offsets ≥ 4 GiB but our test blob is smaller, so this
     * should trigger a bounds error.
     */
    #[Test]
    public function rejectsBigTiffValueOffsetBeyond4GB(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 1);  // 1 entry

        // Entry with value offset > 4 GiB
        $blob .= pack('v', 0x0100)                    // Tag: ImageWidth
            . pack('v', TiffConst::TYPE_LONG8)        // Type
            . pack('P', 100)                          // Count: 100 LONG8 values
            . pack('P', 0x100000000);                 // Offset > 4GB

        $blob .= pack('P', 0);

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests BigTIFF IFD with truncated entry data.
     *
     * EXIF 3.0 §4.5.2 requires complete BigTIFF entries (20 bytes each).
     */
    #[Test]
    public function rejectsBigTiffTruncatedEntry(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 1);     // Claim 1 entry
        $blob .= pack('v', 0x010F); // Tag only (incomplete entry)
        // Missing: type (2), count (8), value/offset (8), next IFD (8)

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Tests that extremely large BigTIFF entry count is rejected.
     *
     * While BigTIFF uses 64-bit counts, unreasonably large values should
     * be rejected to prevent memory exhaustion.
     */
    #[Test]
    public function rejectsBigTiffWithHugeEntryCount(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        // Claim absurdly high entry count (would require terabytes)
        $blob .= pack('P', 0xFFFFFFFFFFFF);

        $reader = new TiffExifReader();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a minimal BigTIFF header.
     *
     * @param int $offsetSize  Offset size (8 or 16).
     * @param int $reserved    Reserved field value (should be 0).
     * @param int $firstIfd    Offset to first IFD.
     *
     * @return string Binary BigTIFF blob.
     */
    private function buildBigTiffHeader(int $offsetSize, int $reserved, int $firstIfd): string
    {
        $blob = 'II'  // Little-endian
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', $offsetSize)
            . pack('v', $reserved)
            . pack('P', $firstIfd);

        // Add minimal IFD if firstIfd is non-zero and within blob
        if ($firstIfd > 0 && $firstIfd === 16) {
            $blob .= pack('P', 0)   // 0 entries
                . pack('P', 0);     // Next IFD offset
        }

        return $blob;
    }
}
