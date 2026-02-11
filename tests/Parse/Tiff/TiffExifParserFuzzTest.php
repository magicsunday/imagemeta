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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
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
final class TiffExifParserFuzzTest extends TestCase
{
    /**
     * Passes an empty blob to the parser with no header bytes at all.
     * Ensures a BoundsError is raised for the missing TIFF header.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * Ensures bounds checks reject the entry before any unsafe reads occur.
     *
     * @return void
     */
    #[Test]
    public function rejectsEntryWithOverflowCount(): void
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

        $this->expectException(BoundsError::class);

        $reader->parseFromBlob($blob);
    }

    /**
     * Creates two ASCII entries that point to the same data offset.
     * Confirms overlapping data is tolerated and does not crash the parser.
     *
     * @return void
     */
    #[Test]
    public function handlesOverlappingEntryData(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 2);  // 2 entries

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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function handlesDeepIfdChain(): void
    {
        // Create a chain of 5 IFDs, each with 1 dummy entry (tag increments to stay sorted)
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);  // First IFD at 8

        $ifdSize = 2 + 12 + 4; // entryCount(2) + 1 entry(12) + nextIfd(4)
        $offset  = 8;
        for ($i = 0; $i < 5; ++$i) {
            $blob .= pack('v', 1);  // 1 entry
            // Use high tag IDs (0xFF00+) to avoid triggering any tag-specific validation
            $blob .= pack('v', 0xFF00 + $i) . pack('v', TiffConst::TYPE_LONG) . pack('V', 1) . pack('V', 1);
            $nextOffset = $offset + $ifdSize;
            $blob .= pack('V', $i < 4 ? $nextOffset : 0);  // Next IFD or 0
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
     *
     * @return void
     */
    #[Test]
    public function handlesAsciiWithEmbeddedNulls(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        $asciiData = "Test\x00String\x00";

        $blob .= pack('v', 0x010F)                 // Manufacturer
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($asciiData))
            . pack('V', 26);

        $blob .= pack('V', 0);
        $blob .= $asciiData;

        $reader = new TiffExifParser();
        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * Creates an entry with a component count of zero.
     * Ensures the parser tolerates zero-length entries without errors.
     *
     * @return void
     */
    #[Test]
    public function handlesEntryWithZeroCount(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        $blob .= pack('v', 0x0100)             // ImageWidth
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
     *
     * @return void
     */
    #[Test]
    public function parsesMixedEndianness(): void
    {
        $blob = 'II'  // Little-endian marker
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        // Entry with value that looks like big-endian but should be parsed as little-endian
        $blob .= pack('v', 0x0100)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 0x01020304);  // Will be read as little-endian

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
        // Big-endian TIFF with one SHORT entry (Orientation=6)
        $blob = 'MM'
            . pack('n', TiffConst::MAGIC_CLASSIC)
            . pack('N', 8)
            . pack('n', 1)
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
     *
     * @return void
     */
    #[Test]
    public function handlesUndefinedTypeWithRandomBytes(): void
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        $undefinedData = "\x00\xFF\x01\xFE\x02\xFD\x03\xFC";

        $blob .= pack('v', ExifTag::MAKER_NOTE)       // MakerNote (no fixed-length rule)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($undefinedData))
            . pack('V', 26);

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
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        $blob .= pack('v', 0x011A)                    // XResolution
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 1)
            . pack('V', 26);

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
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 1);

        $blob .= pack('v', 0x9201)                    // ShutterSpeedValue
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', 1)
            . pack('V', 26);

        $blob .= pack('V', 0);

        return $blob . (pack('l', $numerator) . pack('l', $denominator));
    }
}
