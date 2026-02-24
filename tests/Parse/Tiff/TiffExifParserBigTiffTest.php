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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
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
#[UsesClass(BitMask::class)]
#[UsesClass(BoundsError::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(Endian::class)]
#[UsesClass(ExifNumericList::class)]
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
final class TiffExifParserBigTiffTest extends TestCase
{
    /**
     * Rejects BigTIFF header with offset-size=16 (only 8 is valid per spec).
     */
    #[Test]
    public function rejectsBigTiffWithOffsetSize16(): void
    {
        $blob = $this->buildBigTiffHeader(16, 0, 24);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported BigTIFF offset size');

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Uses an unsupported BigTIFF offset size (12 bytes).
     * Ensures the parser rejects the header with a ParseError.
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
     * EXIF 3.0 §4.5.1 requires a valid 0th IFD offset; zero is rejected.
     */
    #[Test]
    public function rejectsZeroFirstIfdOffsetInBigTiff(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('missing 0th IFD offset');

        $blob = $this->buildBigTiffHeader(8, 0, 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);
    }

    /**
     * Creates a BigTIFF file with a 64-bit entry count and two ASCII entries.
     * Confirms the parser can iterate a 64-bit count and parse inline values.
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

        // IFD with 64-bit entry count (4 entries)
        $blob .= pack('P', 4);  // Entry count (64-bit)

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // Entry 3: Manufacturer (ASCII string "Test")
        $blob .= pack('v', 0x010F)               // Tag
            . pack('v', TiffConst::TYPE_ASCII)   // Type
            . pack('P', 5)                       // Count (64-bit): "Test\0"
            . pack('a8', "Test\0");              // Inline value (8 bytes max in BigTIFF)

        // Entry 4: Model (ASCII string "ABC")
        $blob .= pack('v', 0x0110)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('P', 4)
            . pack('a8', "ABC\0");

        // Next IFD offset (none)
        $blob .= pack('P', 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(4, $result->ifd0->entries);
    }

    /**
     * Builds an IFD entry using the LONG8 type with an inline 64-bit value.
     * Ensures the parser accepts LONG8 and produces an entry for ImageWidth.
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

        // IFD with 2 entries
        $blob .= pack('P', 2);

        // Entry: ImageWidth as LONG8
        $blob .= pack('v', 0x0100)               // Tag: ImageWidth
            . pack('v', TiffConst::TYPE_LONG8)   // Type: LONG8
            . pack('P', 1)                       // Count: 1
            . pack('P', 1920);                   // Inline value: 1920 (fits in 8 bytes)

        // ImageLength SHORT[1] = 1080
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 1080) . pack('a6', '');

        $blob .= pack('P', 0);  // Next IFD

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(2, $result->ifd0->entries);
    }

    /**
     * Builds an entry using the SLONG8 type with a negative value.
     * Confirms signed 64-bit values are accepted and captured in the IFD.
     */
    #[Test]
    public function parsesBigTiffWithSlong8Type(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 3);  // 3 entries

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // Entry with SLONG8 (signed 64-bit value: -42) on a non-dimension tag
        $blob .= pack('v', 0xFF00)                // Tag: dummy (non-pointer tag)
            . pack('v', TiffConst::TYPE_SLONG8)   // Type: SLONG8
            . pack('P', 1)                        // Count
            . pack('q', -42);                     // Inline signed value

        $blob .= pack('P', 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(3, $result->ifd0->entries);
    }

    /**
     * Adds an IFD8 pointer entry that references a zero offset.
     * Verifies the parser accepts the pointer type without attempting a sub-IFD.
     */
    #[Test]
    public function parsesBigTiffWithIfd8PointerType(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        $blob .= pack('P', 3);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 100) . pack('a6', '');

        // Entry: SubIFDs pointer using IFD8 type pointing to offset 0 (no sub-IFD)
        $blob .= pack('v', 0x014A)              // Tag: SubIFDs
            . pack('v', TiffConst::TYPE_IFD8)   // Type: IFD8
            . pack('P', 1)                      // Count
            . pack('P', 0);                     // Pointer: 0 (no sub-IFD)

        $blob .= pack('P', 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertCount(3, $result->ifd0->entries);
    }

    /**
     * Uses a huge first-IFD offset that points beyond the blob size.
     * Ensures bounds checking raises a BoundsError for the invalid offset.
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
     * The parser skips such entries gracefully (GH-1549).
     */
    #[Test]
    public function skipsBigTiffValueOffsetBeyond4GB(): void
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

        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Truncates the entry data so required fields are missing.
     * Ensures the parser throws a BoundsError when the entry is incomplete.
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

        $this->expectException(ParseError::class);

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
        // BigTIFF header: byte-order(2) + magic(2) + offsetSize(2) + reserved(2) + firstIfd($offsetSize)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', $offsetSize)
            . pack('v', $reserved)
            . str_pad(pack('P', $firstIfd), $offsetSize, "\0");

        $headerSize = strlen($blob);

        // Add minimal IFD with ImageWidth + ImageLength + dummy entry if firstIfd points right after header
        if ($firstIfd === $headerSize) {
            // entryCount: 8 bytes (readU64), entry: tag(2)+type(2)+count(8)+value($offsetSize)
            // nextIfd: $offsetSize bytes
            $blob .= pack('P', 3)                                // 3 entries (always 8-byte U64)
                // ImageWidth SHORT[1] = 100
                . pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('P', 1)
                . str_pad(pack('v', 100), $offsetSize, "\0")
                // ImageLength SHORT[1] = 100
                . pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('P', 1)
                . str_pad(pack('v', 100), $offsetSize, "\0")
                // dummy tag
                . pack('v', 0xFF00)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('P', 1)                                    // count (always 8 bytes)
                . str_pad(pack('P', 1), $offsetSize, "\0")       // value (padded to offset size)
                . str_pad(pack('P', 0), $offsetSize, "\0");      // Next IFD offset
        }

        return $blob;
    }
}
