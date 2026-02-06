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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Exercises BigTIFF parsing edge cases that rely on 64-bit offsets and counts.
 * It validates BigTIFF header variants, offset-size rules, and inline value limits.
 * The tests feed malformed or out-of-range structures to assert ParseError/BoundsError.
 * This ensures BigTIFF handling remains strict and safe for large-file metadata.
 */
#[CoversClass(TiffExifParser::class)]
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
final class TiffExifParserBigTiffTest extends TestCase
{
    /**
     * Builds a BigTIFF header that uses the 16-byte offset size variant.
     * Confirms the parser accepts this supported offset size and yields an empty IFD.
     *
     * @return void
     */
    #[Test]
    public function acceptsBigTiffWithOffsetSize16(): void
    {
        $blob = $this->buildBigTiffHeader(16, 0, 16);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertSame([], $result->ifd0->entries);
    }

    /**
     * Uses an unsupported BigTIFF offset size (12 bytes).
     * Ensures the parser rejects the header with a ParseError.
     *
     * @return void
     */
    #[Test]
    public function rejectsBigTiffWithOffsetSize12(): void
    {
        $blob = $this->buildBigTiffHeader(12, 0, 16);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported BigTIFF offset size');

        $reader->parseFromBlob($blob);
    }

    /**
     * Sets the first IFD offset to zero in the BigTIFF header.
     * Verifies the parser treats it as an empty directory rather than reading entries.
     *
     * @return void
     */
    #[Test]
    public function handlesZeroFirstIfdOffsetInBigTiff(): void
    {
        $blob = $this->buildBigTiffHeader(8, 0, 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        // Zero offset should result in empty IFD
        self::assertCount(0, $result->ifd0->entries);
    }

    /**
     * Creates a BigTIFF file with a 64-bit entry count and two ASCII entries.
     * Confirms the parser can iterate a 64-bit count and parse inline values.
     *
     * @return void
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

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(2, $result->ifd0->entries);
    }

    /**
     * Builds an IFD entry using the LONG8 type with an inline 64-bit value.
     * Ensures the parser accepts LONG8 and produces an entry for ImageWidth.
     *
     * @return void
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

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(1, $result->ifd0->entries);
    }

    /**
     * Builds an entry using the SLONG8 type with a negative value.
     * Confirms signed 64-bit values are accepted and captured in the IFD.
     *
     * @return void
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
        $blob .= pack('v', 0x0100)                // Tag: ImageWidth (non-pointer tag)
            . pack('v', TiffConst::TYPE_SLONG8)   // Type: SLONG8
            . pack('P', 1)                        // Count
            . pack('q', -42);                     // Inline signed value

        $blob .= pack('P', 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(1, $result->ifd0->entries);
    }

    /**
     * Adds an IFD8 pointer entry that references a zero offset.
     * Verifies the parser accepts the pointer type without attempting a sub-IFD.
     *
     * @return void
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

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(1, $result->ifd0->entries);
    }

    /**
     * Uses a huge first-IFD offset that points beyond the blob size.
     * Ensures bounds checking raises a BoundsError for the invalid offset.
     *
     * @return void
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

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Provides an entry whose value offset is beyond 4 GiB.
     * Confirms bounds checking rejects the out-of-range offset.
     *
     * @return void
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

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Truncates the entry data so required fields are missing.
     * Ensures the parser throws a BoundsError when the entry is incomplete.
     *
     * @return void
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

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Declares an absurdly large 64-bit entry count in the IFD header.
     * Verifies the parser rejects the file to prevent unrealistic allocations.
     *
     * @return void
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

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Builds a minimal BigTIFF header.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $offsetSize Offset size (8 or 16).
     * @param int $reserved   Reserved field value (should be 0).
     * @param int $firstIfd   Offset to first IFD.
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
        if ($firstIfd === 16) {
            $blob .= pack('P', 0)   // 0 entries
                . pack('P', 0);     // Next IFD offset
        }

        return $blob;
    }
}
