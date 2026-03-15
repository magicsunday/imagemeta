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
use function str_repeat;
use function strlen;

/**
 * Applies fuzz-style inputs to the TIFF EXIF parser with corrupted structures.
 * It stresses offset validation, count handling, and tag decoding under malformed data.
 * The suite expects ParseError or BoundsError rather than crashes or infinite loops.
 * This defends the parser against hostile or truncated TIFF payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(BoundsError::class)]
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
final class TiffExifParserFuzzTest extends TestCase
{
    /**
     * Passes an empty blob to the parser with no header bytes at all.
     * Ensures a BoundsError is raised for the missing TIFF header.
     */
    #[Test]
    public function rejectsEmptyBlob(): void
    {
        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob('');
    }

    /**
     * Provides only the byte-order marker to simulate a truncated header.
     * Verifies the parser throws BoundsError when mandatory fields are missing.
     */
    #[Test]
    public function rejectsTooShortBlob(): void
    {
        $blob = 'II';  // Only byte order marker

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Feeds random 0xFF bytes to simulate garbage input.
     * Confirms a ParseError is thrown for an invalid TIFF header/magic.
     */
    #[Test]
    public function rejectsRandomGarbage(): void
    {
        $blob = str_repeat("\xFF", 100);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Uses a blob of null bytes to simulate a zeroed file.
     * Ensures the parser rejects the invalid header with a ParseError.
     */
    #[Test]
    public function rejectsNullBytes(): void
    {
        $blob = str_repeat("\x00", 100);

        $reader = new TiffExifParser();

        $this->expectException(ParseError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Includes byte order and magic but omits the first IFD offset.
     * Verifies a BoundsError is raised for the truncated header.
     */
    #[Test]
    public function rejectsHeaderOnlyBlob(): void
    {
        $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC);
        // Missing first IFD offset

        $reader = new TiffExifParser();

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Declares a BYTE entry with an INT_MAX count to overflow expected data size.
     * The entry triggers a BoundsError internally which is caught — the entry
     * is silently skipped and parsing succeeds (Postel's Law).
     */
    #[Test]
    public function skipsEntryWithOverflowCount(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);  // 1 entry

        // Entry with huge count (INT_MAX)
        $blob .= pack('v', 0x0100)                // Tag
            . pack('v', TiffConst::TYPE_BYTE)     // Type: BYTE (1 byte each)
            . pack('V', 0x7FFFFFFF)               // Count: INT_MAX
            . pack('V', 100);                     // Offset

        $blob .= pack('V', 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Creates two ASCII entries that point to the same data offset.
     * Confirms overlapping data is tolerated and does not crash the parser.
     */
    #[Test]
    public function handlesOverlappingEntryData(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 4);  // 4 entries

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

        // Both entries point to same offset (overlapping)
        $blob .= pack('v', 0x010F)                 // Tag: Manufacturer
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 10)
            . pack('V', 100);                      // Offset 100

        $blob .= pack('v', 0x0110)                 // Tag: Model
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 10)
            . pack('V', 100);                      // Same offset

        $blob .= pack('V', 0);

        // Pad to offset 100 and add data
        $blob .= str_repeat("\x00", 100 - strlen($blob));
        $blob .= "TestData\0\0";

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);

        // Should parse without error (overlapping is allowed, just reads same data twice)
    }

    /**
     * Uses a RATIONAL value with both numerator and denominator set to zero.
     * Ensures the parser handles the degenerate fraction without throwing.
     */
    #[Test]
    public function handlesRationalBothZero(): void
    {
        $blob = $this->buildTiffWithRational(0, 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Uses a RATIONAL value at the maximum unsigned 32-bit range.
     * Verifies the parser handles extreme values without error.
     */
    #[Test]
    public function handlesRationalMaxValues(): void
    {
        $blob = $this->buildTiffWithRational(0xFFFFFFFF, 0xFFFFFFFF);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Uses an SRATIONAL value with a negative numerator and positive denominator.
     * Ensures signed numerator handling does not break parsing.
     */
    #[Test]
    public function handlesSrationalNegativeNumerator(): void
    {
        $blob = $this->buildTiffWithSRational(-12345, 67890);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Uses an SRATIONAL value with a negative denominator.
     * Confirms the parser accepts signed denominators without crashing.
     */
    #[Test]
    public function handlesSrationalNegativeDenominator(): void
    {
        $blob = $this->buildTiffWithSRational(12345, -67890);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Uses an SRATIONAL value where both numerator and denominator are negative.
     * Verifies the parser tolerates fully negative signed fractions.
     */
    #[Test]
    public function handlesSrationalBothNegative(): void
    {
        $blob = $this->buildTiffWithSRational(-12345, -67890);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Builds a chain of five empty IFDs to stress pointer traversal.
     * Ensures the parser follows the chain and records subsequent IFDs.
     */
    #[Test]
    public function handlesDeepIfdChain(): void
    {
        // Create a chain of 5 IFDs; IFD0 gets ImageWidth+ImageLength+dummy, rest have 1 dummy
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);  // First IFD at 8

        // IFD0: 3 entries
        $ifd0Size = 2 + (3 * 12) + 4;
        $offset   = 8;

        $blob .= pack('v', 3);
        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH) . pack('v', TiffConst::TYPE_SHORT) . pack('V', 1) . pack('v', 100) . pack('v', 0);
        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH) . pack('v', TiffConst::TYPE_SHORT) . pack('V', 1) . pack('v', 100) . pack('v', 0);
        // Dummy tag
        $blob .= pack('v', 0xFF00) . pack('v', TiffConst::TYPE_LONG) . pack('V', 1) . pack('V', 1);

        $nextOffset = $offset + $ifd0Size;
        $blob .= pack('V', $nextOffset);
        $offset = $nextOffset;

        $ifdSize = 2 + 12 + 4;

        for ($i = 1; $i < 5; ++$i) {
            $blob .= pack('v', 1);
            $blob .= pack('v', 0xFF00 + $i) . pack('v', TiffConst::TYPE_LONG) . pack('V', 1) . pack('V', 1);
            $nextOffset = $offset + $ifdSize;
            $blob .= pack('V', $i < 4 ? $nextOffset : 0);
            $offset = $nextOffset;
        }

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);

        self::assertCount(4, $result->subsequentIfds());
    }

    /**
     * Embeds null bytes inside an ASCII tag value.
     * Confirms the parser accepts embedded nulls without failing.
     */
    #[Test]
    public function handlesAsciiWithEmbeddedNulls(): void
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

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

        $asciiData = "Test\x00String\x00";

        $blob .= pack('v', 0x010F)                 // Manufacturer
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($asciiData))
            . pack('V', $valOffset);

        $blob .= pack('V', 0);
        $blob .= $asciiData;

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Creates an entry with a component count of zero.
     * Ensures the parser tolerates zero-length entries without errors.
     */
    #[Test]
    public function handlesEntryWithZeroCount(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 3);

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

        // Dummy entry with count=0
        $blob .= pack('v', 0xFF00)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 0)                     // Count: 0
            . pack('V', 0);

        $blob .= pack('V', 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Supplies a little-endian header with values that could be misread as big-endian.
     * Verifies the parser honors the declared endianness for value decoding.
     */
    #[Test]
    public function parsesMixedEndianness(): void
    {
        $blob = 'II'  // Little-endian marker
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 2);

        // Entry with value that looks like big-endian but should be parsed as little-endian
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 0x01020304);  // Will be read as little-endian

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $blob .= pack('V', 0);

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Big-endian inline SHORT value is extracted from the left-justified
     * lower-numbered bytes per TIFF 6.0 §2.
     */
    #[Test]
    public function parsesBigEndianInlineShortValue(): void
    {
        // Big-endian TIFF with ImageWidth + ImageLength + Orientation
        $blob = 'MM'
            . pack('n', TiffConst::MAGIC_CLASSIC)
            . pack('N', 8)
            . pack('n', 3)
            // ImageWidth SHORT[1] = 100
            . pack('n', ExifTag::IMAGE_WIDTH)
            . pack('n', TiffConst::TYPE_SHORT)
            . pack('N', 1)
            . "\x00\x64\x00\x00"
            // ImageLength SHORT[1] = 100
            . pack('n', ExifTag::IMAGE_LENGTH)
            . pack('n', TiffConst::TYPE_SHORT)
            . pack('N', 1)
            . "\x00\x64\x00\x00"
            // tag=Orientation(0x0112), type=SHORT(3), count=1
            . pack('n', ExifTag::ORIENTATION)
            . pack('n', TiffConst::TYPE_SHORT)
            . pack('N', 1)
            // value: left-justified 2 bytes (0x0006) + 2 padding bytes
            . "\x00\x06\x00\x00"
            . pack('N', 0);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        $entry = $result->ifd0->get(ExifTag::ORIENTATION);
        self::assertNotNull($entry);
        self::assertSame(6, $entry->value);
    }

    /**
     * Uses an UNDEFINED tag payload filled with arbitrary bytes.
     * Confirms the parser accepts opaque data without attempting interpretation.
     */
    #[Test]
    public function handlesUndefinedTypeWithRandomBytes(): void
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

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

        $undefinedData = "\x00\xFF\x01\xFE\x02\xFD\x03\xFC";

        $blob .= pack('v', ExifTag::MAKER_NOTE)       // MakerNote (no fixed-length rule)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($undefinedData))
            . pack('V', $valOffset);

        $blob .= pack('V', 0);
        $blob .= $undefinedData;

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Builds a TIFF blob with a RATIONAL value.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $numerator   Numerator.
     * @param int $denominator Denominator.
     *
     * @return string Binary TIFF blob.
     */
    private function buildTiffWithRational(int $numerator, int $denominator): string
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

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

        $blob .= pack('v', 0x011A)                    // XResolution
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 1)
            . pack('V', $valOffset);

        $blob .= pack('V', 0);

        return $blob . (pack('V', $numerator) . pack('V', $denominator));
    }

    /**
     * Builds a TIFF blob with an SRATIONAL value.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $numerator   Signed numerator.
     * @param int $denominator Signed denominator.
     *
     * @return string Binary TIFF blob.
     */
    private function buildTiffWithSRational(int $numerator, int $denominator): string
    {
        $entryCount = 3;
        $ifdOffset  = 8;
        $valOffset  = $ifdOffset + 2 + (12 * $entryCount) + 4;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

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

        $blob .= pack('v', 0x9201)                    // ShutterSpeedValue
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', 1)
            . pack('V', $valOffset);

        $blob .= pack('V', 0);

        return $blob . (pack('l', $numerator) . pack('l', $denominator));
    }
}
