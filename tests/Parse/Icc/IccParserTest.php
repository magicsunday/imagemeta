<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Icc;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Icc\IccTag;
use MagicSunday\ImageMeta\Parse\Icc\IccBinaryReader;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
use MagicSunday\ImageMeta\Tests\Fixtures\Icc\IccFixtures;
use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function ceil;
use function chr;
use function count;
use function intdiv;
use function pack;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;

/**
 * Exercises the ICC parser against minimal profiles and segmented payloads.
 * It validates header decoding such as version, PCS, rendering intent, and profile ID.
 * The tests confirm segment reassembly yields the same decoded header data as a full blob.
 * Error cases cover truncated or inconsistent profiles to ensure safe failures.
 */
#[UsesClass(ParseError::class)]
#[UsesClass(IccRenderingIntent::class)]
#[UsesClass(IccBinaryReader::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccTagDecoder::class)]
#[CoversClass(IccParser::class)]
final class IccParserTest extends TestCase
{
    /**
     * Decodes a full ICC profile and extracts key header fields.
     * This verifies version parsing, PCS detection, rendering intent, and profile ID formatting.
     */
    #[Test]
    public function decodeExtractsHeaderFields(): void
    {
        $profile = IccFixtures::minimalProfile();

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('2.4', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertNull($result['profileId']);
    }

    /**
     * Splits the ICC profile into segments and reassembles them.
     * This confirms segment concatenation yields the same decoded header fields.
     */
    #[Test]
    public function decodeReassemblesSegments(): void
    {
        $profile = IccFixtures::minimalProfile();

        $half     = intdiv(strlen($profile) + 1, 2);
        $segments = [
            $this->createSegment(1, 2, substr($profile, 0, $half)),
            $this->createSegment(2, 2, substr($profile, $half)),
        ];

        $decoder = new IccParser();
        $result  = $decoder->decode(null, $segments);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('2.4', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertNull($result['profileId']);
    }

    /**
     * Supplies truncated payloads and incomplete segment lists.
     * This verifies the decoder returns null when required data is missing.
     */
    #[Test]
    public function decodeReturnsNullForTruncatedData(): void
    {
        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode('short');
    }

    /**
     * Distinguishes missing ICC payloads from malformed ICC payloads.
     * Missing data returns null, while malformed present data must throw ParseError.
     */
    #[Test]
    public function decodeDistinguishesMissingProfileFromMalformedProfile(): void
    {
        $decoder = new IccParser();

        self::assertNull($decoder->decode(null));

        $this->expectException(ParseError::class);
        $decoder->decode('short');
    }

    /**
     * Guards staged decode refactoring by requiring explicit decode-phase helpers.
     */
    #[Test]
    public function decodeUsesExplicitDecodeStages(): void
    {
        $reflection = new ReflectionClass(IccParser::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('selectDecodeInput', $methods);
        self::assertContains('validateAndNormalizeProfileData', $methods);
        self::assertContains('decodeHeaderFields', $methods);
        self::assertContains('decodeTagFields', $methods);
    }

    /**
     * Verifies that valid fixed-width unsigned big-endian fields decode deterministically.
     */
    #[Test]
    public function uIntHelpersDecodeValidFixedWidthValues(): void
    {
        $reader = new IccBinaryReader();

        self::assertSame(0x01020304, $reader->uInt32Be("\x01\x02\x03\x04"));
        self::assertSame(0x0102, $reader->uInt16Be("\x01\x02"));
    }

    /**
     * Rejects truncated uInt32Number fields instead of applying implicit zero-padding.
     */
    #[Test]
    public function uInt32HelperRejectsTruncatedFields(): void
    {
        $reader = new IccBinaryReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ICC uInt32 field truncated: expected 4 bytes, got 3');

        $reader->uInt32Be("\x01\x02\x03");
    }

    /**
     * Rejects truncated uInt16Number fields instead of applying implicit zero-padding.
     */
    #[Test]
    public function uInt16HelperRejectsTruncatedFields(): void
    {
        $reader = new IccBinaryReader();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ICC uInt16 field truncated: expected 2 bytes, got 1');

        $reader->uInt16Be("\x01");
    }

    /**
     * Feeds out-of-order ICC segments to the decoder.
     * This confirms the decoder sorts fragments before reconstruction.
     */
    #[Test]
    public function decodeHandlesOutOfOrderSegments(): void
    {
        $profile = IccFixtures::minimalProfile();

        $half     = intdiv(strlen($profile) + 1, 2);
        $segments = [
            $this->createSegment(2, 2, substr($profile, $half)),
            $this->createSegment(1, 2, substr($profile, 0, $half)),
        ];

        $decoder = new IccParser();
        $result  = $decoder->decode(null, $segments);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('2.4', $result['version']);
    }

    /**
     * Drops the middle segment from a multi-part profile.
     * This ensures the decoder rejects incomplete segment sequences.
     */
    #[Test]
    public function decodeRejectsIncompleteSegmentSequences(): void
    {
        $profile = IccFixtures::minimalProfile();

        $third    = intdiv(strlen($profile), 3);
        $segments = [
            $this->createSegment(1, 3, substr($profile, 0, $third)),
            $this->createSegment(3, 3, substr($profile, $third * 2)),
        ];

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode(null, $segments);
    }

    /**
     * Tolerates profiles whose declared size is not 4-byte aligned.
     */
    #[Test]
    public function toleratesMisalignedProfileSize(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set profileSize to 243 (not 4-byte aligned) and pad to match
        $profile = substr_replace($profile, pack('N', 243), 0, 4);
        $profile = substr($profile, 0, 243);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Rejects profiles where the declared size is below the 128-byte header minimum.
     */
    #[Test]
    public function decodeRejectsProfileSizeBelowMinimum(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set profileSize to 64 (below 128 minimum)
        $profile = substr_replace($profile, pack('N', 64), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Tolerates profiles with trailing bytes beyond the declared size.
     */
    #[Test]
    public function toleratesProfileSizeTrailingBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Append 4 extra bytes without updating profileSize
        $profile .= str_repeat("\0", 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates truncated profiles where the declared size exceeds available data.
     */
    #[Test]
    public function toleratesTruncatedProfile(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Truncate to 200 bytes while profileSize claims 244
        $profile = substr($profile, 0, 200);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Accepts a valid tag table with contiguous non-overlapping data ranges.
     *
     * ICC.1:2022 §7.3 allows distinct tags when ranges are disjoint and contiguous.
     */
    #[Test]
    public function decodeAcceptsContiguousNonOverlappingTagTableRanges(): void
    {
        $profile = $this->buildProfileWithCustomTagTable(
            [
                ['signature' => 'desc', 'offset' => 156, 'size' => 4],
                ['signature' => 'cprt', 'offset' => 160, 'size' => 4],
            ],
            'ABCDWXYZ',
        );

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Accepts shared tag data when offset and size are identical.
     *
     * ICC.1:2022 §7.3 allows aliasing only for exactly matching data elements.
     */
    #[Test]
    public function decodeAcceptsSharedOffsetWithMatchingSize(): void
    {
        $profile = $this->buildProfileWithCustomTagTable(
            [
                ['signature' => 'desc', 'offset' => 156, 'size' => 8],
                ['signature' => 'cprt', 'offset' => 156, 'size' => 8],
            ],
            'ABCDEFGH',
        );

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Parses an ICC profile with version 2.1.3 encoding.
     * This verifies version parsing uses the correct byte layout:
     * byte 8 = major, byte 9 high nibble = minor, low nibble = bugfix.
     */
    #[Test]
    public function decodeExtractsVersionEncoding(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, chr(0x02), 8, 1);
        $profile = substr_replace($profile, chr(0x13), 9, 1);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('2.1.3', $result['version']);
    }

    /**
     * Parses a profile with a valid ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires valid calendar/time ranges.
     */
    #[Test]
    public function parsesValidDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 15, 12, 30, 45);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('2024:06:15 12:30:45', $result['profileDateTime']);
        self::assertSame('2024:06:15 12:30:45Z', $result['profileDateTimeUtc']);
    }

    /**
     * Rejects year zero in ICC dateTimeNumber.
     * ICC.1:2022 §4.2 and §7.2.6 define a valid date/time value.
     */
    #[Test]
    public function rejectsYearZeroInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 0, 6, 15, 12, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Accepts lower-boundary values in ICC dateTimeNumber.
     * ICC.1:2022 §4.2 and §7.2.6: month=1, day=1, hour=0, minute=0, second=0.
     */
    #[Test]
    public function acceptsLowerBoundaryDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 1, 1, 0, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('2024:01:01 00:00:00', $result['profileDateTime']);
        self::assertSame('2024:01:01 00:00:00Z', $result['profileDateTimeUtc']);
    }

    /**
     * Accepts upper-boundary values in ICC dateTimeNumber.
     * ICC.1:2022 §4.2 and §7.2.6: month=12, day=31, hour=23, minute=59, second=59.
     */
    #[Test]
    public function acceptsUpperBoundaryDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 12, 31, 23, 59, 59);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('2024:12:31 23:59:59', $result['profileDateTime']);
        self::assertSame('2024:12:31 23:59:59Z', $result['profileDateTimeUtc']);
    }

    /**
     * Rejects month zero in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires month in range 1..12.
     */
    #[Test]
    public function rejectsMonthZeroInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 0, 15, 12, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects month thirteen in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires month in range 1..12.
     */
    #[Test]
    public function rejectsMonthThirteenInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 13, 15, 12, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects day zero in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires day in range 1..31.
     */
    #[Test]
    public function rejectsDayZeroInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 0, 10, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects day 32 in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires day in range 1..31.
     */
    #[Test]
    public function rejectsDayThirtyTwoInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 32, 10, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects hour 24 in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires hour in range 0..23.
     */
    #[Test]
    public function rejectsHourTwentyFourInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 15, 24, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects minute 60 in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires minute in range 0..59.
     */
    #[Test]
    public function rejectsMinuteSixtyInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 15, 10, 60, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Rejects second 60 in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6 requires second in range 0..59.
     */
    #[Test]
    public function rejectsSecondSixtyInDateTimeNumber(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 6, 15, 10, 0, 60);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['profileDateTime']);
        self::assertNull($result['profileDateTimeUtc']);
    }

    /**
     * Accepts Feb 29 on a leap year in ICC dateTimeNumber.
     * ICC.1:2022 §7.2.6: 2024 is a leap year, so Feb 29 is valid.
     */
    #[Test]
    public function acceptsLeapDayOnLeapYear(): void
    {
        $profile  = IccFixtures::minimalProfile();
        $dateTime = pack('nnnnnn', 2024, 2, 29, 10, 0, 0);
        $profile  = substr_replace($profile, $dateTime, IccTag::PROFILE_DATE_TIME, 12);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('2024:02:29 10:00:00', $result['profileDateTime']);
        self::assertSame('2024:02:29 10:00:00Z', $result['profileDateTimeUtc']);
    }

    /**
     * Rejects odd-length UTF-16BE payload in ICC mluc record.
     * ICC.1:2022 §10.13: UTF-16BE must consist of complete code units.
     */
    #[Test]
    public function rejectsOddLengthUtf16InMluc(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x65\x00"); // 5 bytes = odd

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Odd-length UTF-16BE payload');

        $decoder->decode($profile);
    }

    /**
     * Parses a valid UTF-16BE mluc description.
     * ICC.1:2022 §10.13: mluc record with even-length UTF-16BE string.
     */
    #[Test]
    public function parsesValidMlucDescription(): void
    {
        // "Hi" in UTF-16BE
        $utf16 = "\x00\x48\x00\x69";

        $profile = $this->buildMlucProfile($utf16);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Hi', $result['description']);
    }

    /**
     * Tolerates mluc payloads with non-zero reserved bytes in the type header.
     */
    #[Test]
    public function toleratesMlucTagWithNonZeroTypeReservedBytes(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, "\0\0\0\x01", $tagDataOffset + 4, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Hi', $result['description']);
    }

    /**
     * Tolerates mluc payloads with record size larger than 12.
     */
    #[Test]
    public function toleratesMlucTagWithLargerRecordSize(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, pack('N', 16), $tagDataOffset + 12, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Rejects mluc payloads with truncated record table data.
     * ICC.1:2022 Table 54: 16 + recordCount * recordSize must fit in the tag payload.
     */
    #[Test]
    public function rejectsMlucTagWithTruncatedRecordTable(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, pack('N', 2), $tagDataOffset + 8, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('record table exceeds payload bounds');

        $decoder->decode($profile);
    }

    /**
     * Rejects mluc payloads with out-of-bounds string ranges.
     * ICC.1:2022 Table 54: each string offset/length must stay within the tag payload.
     */
    #[Test]
    public function rejectsMlucTagWithOutOfBoundsStringRange(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, pack('N', 100), $tagDataOffset + 20, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('string range');

        $decoder->decode($profile);
    }

    /**
     * Rejects mluc payloads whose string offset alone is large enough that adding the
     * string length would overflow on narrow integer platforms, or whose offset already
     * exceeds the payload bounds before the sum is formed.
     *
     * ICC.1:2022 Table 54: each string offset/length must stay within the tag payload,
     * and the range check must be overflow-safe.
     */
    #[Test]
    public function rejectsMlucTagWithStringRangeOverflow(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        // Set string offset to 0xFFFFFFFF — a near-maximum uint32 value that would cause
        // integer overflow on 32-bit platforms when added to any non-zero string length.
        $profile = substr_replace($profile, pack('N', 0xFFFFFFFF), $tagDataOffset + 24, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1890);

        $decoder->decode($profile);
    }

    /**
     * Multi-record mluc with reordered records yields same selected output.
     *
     * The parser must select deterministically by locale, not by record order.
     */
    #[Test]
    public function mlucMultiRecordSelectsDeterministicallyRegardlessOfOrder(): void
    {
        // "Hallo" (deDE) first, "Hello" (enUS) second
        $profileA = $this->buildMultiRecordMlucProfile([
            ['lang' => 'de', 'country' => 'DE', 'text' => "\x00\x48\x00\x61\x00\x6c\x00\x6c\x00\x6f"],
            ['lang' => 'en', 'country' => 'US', 'text' => "\x00\x48\x00\x65\x00\x6c\x00\x6c\x00\x6f"],
        ]);
        // Reversed: "Hello" (enUS) first, "Hallo" (deDE) second
        $profileB = $this->buildMultiRecordMlucProfile([
            ['lang' => 'en', 'country' => 'US', 'text' => "\x00\x48\x00\x65\x00\x6c\x00\x6c\x00\x6f"],
            ['lang' => 'de', 'country' => 'DE', 'text' => "\x00\x48\x00\x61\x00\x6c\x00\x6c\x00\x6f"],
        ]);

        $decoder = new IccParser();
        $resultA = $decoder->decode($profileA);
        $resultB = $decoder->decode($profileB);

        self::assertNotNull($resultA);
        self::assertNotNull($resultB);
        self::assertSame($resultA['description'], $resultB['description']);
        self::assertSame('Hello', $resultA['description']);
    }

    /**
     * Multi-record mluc with enUS locale present selects enUS.
     */
    #[Test]
    public function mlucSelectsEnUsWhenPresent(): void
    {
        $profile = $this->buildMultiRecordMlucProfile([
            ['lang' => 'fr', 'country' => 'FR', 'text' => "\x00\x42\x00\x6f\x00\x6e\x00\x6a\x00\x6f\x00\x75\x00\x72"],
            ['lang' => 'en', 'country' => 'US', 'text' => "\x00\x48\x00\x65\x00\x6c\x00\x6c\x00\x6f"],
        ]);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Hello', $result['description']);
    }

    /**
     * Multi-record mluc without enUS falls back to first non-empty record deterministically.
     */
    #[Test]
    public function mlucFallsBackToFirstRecordWithoutEnUs(): void
    {
        $profile = $this->buildMultiRecordMlucProfile([
            ['lang' => 'de', 'country' => 'DE', 'text' => "\x00\x48\x00\x61\x00\x6c\x00\x6c\x00\x6f"],
            ['lang' => 'fr', 'country' => 'FR', 'text' => "\x00\x42\x00\x6f\x00\x6e\x00\x6a\x00\x6f\x00\x75\x00\x72"],
        ]);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Hallo', $result['description']);
    }

    /**
     * Builds a minimal ICC profile with an mluc description tag containing the given UTF-16BE string.
     */
    private function buildMlucProfile(string $utf16String): string
    {
        // ICC header (128 bytes)
        $header = pack('N', 0)           // Profile size (placeholder, patched below)
            . str_repeat("\0", 4)        // Preferred CMM type
            . pack('N', 0x04200000)      // Version 4.2.0
            . str_repeat("\0", 4)        // Device class
            . 'RGB '                     // Color space
            . 'XYZ '                     // PCS
            . str_repeat("\0", 12)       // Date/time (year=0 → null)
            . 'acsp'                     // Profile signature
            . str_repeat("\0", 24)       // Primary platform + flags + device mfg + model + attributes
            . pack('N', 0)              // Rendering intent (perceptual)
            . pack('N', 0x0000F6D6)     // PCS illuminant X (D50)
            . pack('N', 0x00010000)     // PCS illuminant Y (D50)
            . pack('N', 0x0000D32D)     // PCS illuminant Z (D50)
            . str_repeat("\0", 4)       // Profile creator
            . str_repeat("\0", 16)       // Profile ID
            . str_repeat("\0", 28);      // Reserved

        // Pad header to exactly 128 bytes
        $header = str_pad($header, 128, "\0");

        // mluc tag data: signature + reserved + recordCount + recordSize + record + string
        $stringOffset = 16 + 12; // after mluc header (16) + 1 record (12)
        $mlucTag      = 'mluc'
            . pack('N', 0)               // Reserved
            . pack('N', 1)               // Record count
            . pack('N', 12)              // Record size
            . 'enUS'                     // Language + country
            . pack('N', strlen($utf16String)) // String length
            . pack('N', $stringOffset)   // String offset (relative to tag start)
            . $utf16String;

        // ICC.1:2022 §7.3: tag size must be 4-byte aligned
        $paddedSize = (int) (ceil(strlen($mlucTag) / 4) * 4);
        $mlucTag    = str_pad($mlucTag, $paddedSize, "\0");

        // Tag table: 1 entry (desc)
        $tagOffset = 128 + 4 + 12; // header + tagCount(4) + 1 tag entry(12)
        $tagTable  = pack('N', 1)        // Tag count
            . 'desc'                     // Tag signature
            . pack('N', $tagOffset)      // Offset to tag data
            . pack('N', $paddedSize);    // Tag size (4-byte aligned)

        $profile = $header . $tagTable . $mlucTag;

        // Patch profile size
        return pack('N', strlen($profile)) . substr($profile, 4);
    }

    /**
     * Builds a minimal ICC v4 profile with a multi-record mluc description tag.
     *
     * @param list<array{lang: string, country: string, text: string}> $records
     */
    private function buildMultiRecordMlucProfile(array $records): string
    {
        $header = pack('N', 0)
            . str_repeat("\0", 4)
            . pack('N', 0x04200000)      // Version 4.2.0
            . str_repeat("\0", 4)
            . 'RGB '
            . 'XYZ '
            . str_repeat("\0", 12)
            . 'acsp'
            . str_repeat("\0", 24)
            . pack('N', 0)
            . pack('N', 0x0000F6D6)
            . pack('N', 0x00010000)
            . pack('N', 0x0000D32D)
            . str_repeat("\0", 4)
            . str_repeat("\0", 16)
            . str_repeat("\0", 28);

        $header = str_pad($header, 128, "\0");

        $recordCount    = count($records);
        $recordSize     = 12;
        $stringAreaBase = 16 + ($recordCount * $recordSize);

        $recordBytes = '';
        $stringBytes = '';
        $stringPos   = $stringAreaBase;

        foreach ($records as $record) {
            $recordBytes .= $record['lang']
                . $record['country']
                . pack('N', strlen($record['text']))
                . pack('N', $stringPos);
            $stringBytes .= $record['text'];
            $stringPos += strlen($record['text']);
        }

        $mlucTag = 'mluc'
            . pack('N', 0)
            . pack('N', $recordCount)
            . pack('N', $recordSize)
            . $recordBytes
            . $stringBytes;

        $paddedSize = (int) (ceil(strlen($mlucTag) / 4) * 4);
        $mlucTag    = str_pad($mlucTag, $paddedSize, "\0");

        $tagOffset = 128 + 4 + 12;
        $tagTable  = pack('N', 1)
            . 'desc'
            . pack('N', $tagOffset)
            . pack('N', $paddedSize);

        $profile = $header . $tagTable . $mlucTag;

        return pack('N', strlen($profile)) . substr($profile, 4);
    }

    /**
     * Tolerates profiles with non-zero reserved bytes in version field.
     */
    #[Test]
    public function toleratesNonZeroVersionReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, chr(0x01), 10, 1);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates profiles with non-zero reserved byte 11 in version field.
     */
    #[Test]
    public function toleratesNonZeroVersionReservedByte11(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, chr(0xFF), 11, 1);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates profiles with both non-zero reserved bytes in version field.
     */
    #[Test]
    public function toleratesBothNonZeroVersionReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, "\x01\xFF", 10, 2);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Rejects textType tags without trailing NUL byte.
     * ICC.1:2022 §10.24: textType must be NUL-terminated.
     */
    #[Test]
    public function decodeRejectsTextTypeWithoutTrailingNul(): void
    {
        // Use text of exactly 4 bytes so 4-byte alignment padding doesn't inadvertently add a NUL
        // ICC v2: textType is permitted as a legacy fallback for cprt
        $profile = $this->buildTextTypeProfile('Hell', 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['copyright']); // text tag is invalid
    }

    /**
     * Rejects textType tags without any payload bytes after the type header.
     * ICC.1:2022 §10.24 requires a NUL-terminated 7-bit ASCII text payload.
     */
    #[Test]
    public function decodeRejectsTextTypeWithoutPayload(): void
    {
        // ICC v2: textType is permitted as a legacy fallback for cprt
        $profile = $this->buildTextTypeProfile('', 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['copyright']);
    }

    /**
     * Rejects textType tags with non-ASCII bytes.
     * ICC.1:2022 §10.24: textType must contain only 7-bit ASCII (bytes <= 0x7F).
     */
    #[Test]
    public function decodeRejectsTextTypeWithNonAsciiBytes(): void
    {
        // String with byte 0x80 (non-7-bit-ASCII)
        // ICC v2: textType is permitted as a legacy fallback for cprt
        $profile = $this->buildTextTypeProfile("Test\x80Text\0", 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['copyright']); // text tag is invalid
    }

    /**
     * Accepts valid textType tags with 7-bit ASCII and NUL termination.
     * ICC.1:2022 §10.24: textType with valid 7-bit ASCII.
     */
    #[Test]
    public function decodeAcceptsValidTextType(): void
    {
        // ICC v2: textType is permitted as a legacy fallback for cprt
        $profile = $this->buildTextTypeProfile("Valid ASCII Text\0", 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Valid ASCII Text', $result['copyright']);
    }

    /**
     * Rejects textType tags with non-zero reserved bytes in the type header.
     * ICC.1:2022 §10.1 and §10.24: bytes 4..7 must be zero.
     */
    #[Test]
    public function decodeRejectsTextTypeWithNonZeroReservedBytes(): void
    {
        $profile = $this->buildTextTypeProfile("Valid ASCII Text\0", 0x02400000);

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, "\0\0\0\x01", $tagDataOffset + 4, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['copyright']);
    }

    /**
     * Builds a minimal ICC profile with a textType copyright tag.
     */
    private function buildTextTypeProfile(string $text, int $versionBytes = 0x04210000): string
    {
        // ICC header (128 bytes)
        $header = pack('N', 0)           // Profile size (placeholder, patched below)
            . str_repeat("\0", 4)        // Preferred CMM type
            . pack('N', $versionBytes)   // Version
            . str_repeat("\0", 4)        // Device class
            . 'RGB '                     // Color space
            . 'XYZ '                     // PCS
            . str_repeat("\0", 12)       // Date/time (year=0 → null)
            . 'acsp'                     // Profile signature
            . str_repeat("\0", 24)       // Primary platform + flags + device mfg + model + attributes
            . pack('N', 0)              // Rendering intent (perceptual)
            . pack('N', 0x0000F6D6)     // PCS illuminant X (D50)
            . pack('N', 0x00010000)     // PCS illuminant Y (D50)
            . pack('N', 0x0000D32D)     // PCS illuminant Z (D50)
            . str_repeat("\0", 4)       // Profile creator
            . str_repeat("\0", 16)       // Profile ID
            . str_repeat("\0", 28);      // Reserved

        // Pad header to exactly 128 bytes
        $header = str_pad($header, 128, "\0");

        // textType tag data: signature + reserved + ASCII text
        $textTag = 'text'
            . pack('N', 0)               // Reserved
            . $text;

        // ICC.1:2022 §7.3: tag size must be 4-byte aligned
        $paddedSize = (int) (ceil(strlen($textTag) / 4) * 4);
        $textTag    = str_pad($textTag, $paddedSize, "\0");

        // Tag table: 1 entry (cprt)
        $tagOffset = 128 + 4 + 12; // header + tagCount(4) + 1 tag entry(12)
        $tagTable  = pack('N', 1)        // Tag count
            . 'cprt'                     // Tag signature
            . pack('N', $tagOffset)      // Offset to tag data
            . pack('N', $paddedSize);    // Tag size (4-byte aligned)

        $profile = $header . $tagTable . $textTag;

        // Patch profile size
        return pack('N', strlen($profile)) . substr($profile, 4);
    }

    /**
     * Accepts valid desc tag with NUL-terminated 7-bit ASCII.
     * ICC spec: desc ASCII string must be NUL-terminated and 7-bit ASCII.
     */
    #[Test]
    public function decodeAcceptsValidDescTag(): void
    {
        // ICC v2: descType is permitted as a legacy fallback for desc tag
        $profile = $this->buildDescTypeProfile("Valid ASCII\0", 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Valid ASCII', $result['description']);
    }

    /**
     * Rejects desc tags with non-ASCII bytes.
     * ICC spec: desc ASCII string must contain only 7-bit ASCII (bytes <= 0x7F).
     */
    #[Test]
    public function decodeRejectsDescTagWithNonAsciiBytes(): void
    {
        // String with byte 0x80 (non-7-bit-ASCII)
        // ICC v2: descType is permitted as a legacy fallback for desc tag
        $profile = $this->buildDescTypeProfile("Test\x80Text\0", 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['description']); // desc tag is invalid
    }

    /**
     * Rejects desc tags without trailing NUL byte.
     * ICC spec: desc ASCII string must be NUL-terminated.
     */
    #[Test]
    public function decodeRejectsDescTagWithoutTrailingNul(): void
    {
        // Use text of exactly 12 bytes so 4-byte alignment padding doesn't inadvertently add a NUL
        // ICC v2: descType is permitted as a legacy fallback for desc tag
        $profile = $this->buildDescTypeProfile('Hello World!', 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['description']); // desc tag is invalid
    }

    /**
     * Rejects desc tags with length exceeding available data.
     * ICC spec: asciiLength must not exceed available payload.
     */
    #[Test]
    public function decodeRejectsDescTagWithExcessiveLength(): void
    {
        // ICC v2: descType is permitted as a legacy fallback for desc tag
        $profile = $this->buildDescTypeProfileWithLength("Test\0", 1000, 0x02400000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['description']); // desc tag is invalid
    }

    /**
     * Rejects desc tags with non-zero reserved bytes in the type header.
     * ICC.1:2022 §10.1: bytes 4..7 must be zero.
     */
    #[Test]
    public function decodeRejectsDescTagWithNonZeroReservedBytes(): void
    {
        $profile = $this->buildDescTypeProfile("Valid ASCII\0", 0x02400000);

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, "\0\0\0\x01", $tagDataOffset + 4, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['description']);
    }

    /**
     * ICC v4+: textType is not a permitted payload for cprt (only mluc is conforming).
     *
     * ICC.1:2022 §9.2.22: copyrightTag permitted type is multiLocalizedUnicodeType.
     */
    #[Test]
    public function rejectsTextTypeForCprtInModernProfile(): void
    {
        $profile = $this->buildTextTypeProfile("Valid ASCII Text\0", 0x04200000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['copyright']);
    }

    /**
     * ICC v4+: descType is not a permitted payload for desc tag (only mluc is conforming).
     *
     * ICC.1:2022 §9.2.43: profileDescriptionTag permitted type is multiLocalizedUnicodeType.
     */
    #[Test]
    public function rejectsDescTypeForDescInModernProfile(): void
    {
        $profile = $this->buildDescTypeProfile("Valid ASCII\0", 0x04200000);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNull($result['description']);
    }

    /**
     * Builds a minimal ICC profile with a descType description tag.
     */
    private function buildDescTypeProfile(string $text, int $versionBytes = 0x04210000): string
    {
        return $this->buildDescTypeProfileWithLength($text, strlen($text), $versionBytes);
    }

    /**
     * Builds a minimal ICC profile with a descType description tag with custom length field.
     */
    private function buildDescTypeProfileWithLength(string $text, int $asciiLength, int $versionBytes = 0x04210000): string
    {
        // ICC header (128 bytes)
        $header = pack('N', 0)           // Profile size (placeholder, patched below)
            . str_repeat("\0", 4)        // Preferred CMM type
            . pack('N', $versionBytes)   // Version
            . str_repeat("\0", 4)        // Device class
            . 'RGB '                     // Color space
            . 'XYZ '                     // PCS
            . str_repeat("\0", 12)       // Date/time (year=0 → null)
            . 'acsp'                     // Profile signature
            . str_repeat("\0", 24)       // Primary platform + flags + device mfg + model + attributes
            . pack('N', 0)              // Rendering intent (perceptual)
            . pack('N', 0x0000F6D6)     // PCS illuminant X (D50)
            . pack('N', 0x00010000)     // PCS illuminant Y (D50)
            . pack('N', 0x0000D32D)     // PCS illuminant Z (D50)
            . str_repeat("\0", 4)       // Profile creator
            . str_repeat("\0", 16)       // Profile ID
            . str_repeat("\0", 28);      // Reserved

        // Pad header to exactly 128 bytes
        $header = str_pad($header, 128, "\0");

        // descType tag data: signature + reserved + ASCII length + ASCII text
        $descTag = 'desc'
            . pack('N', 0)               // Reserved
            . pack('N', $asciiLength)    // ASCII length (custom value)
            . $text;

        // ICC.1:2022 §7.3: tag size must be 4-byte aligned
        $paddedSize = (int) (ceil(strlen($descTag) / 4) * 4);
        $descTag    = str_pad($descTag, $paddedSize, "\0");

        // Tag table: 1 entry (desc)
        $tagOffset = 128 + 4 + 12; // header + tagCount(4) + 1 tag entry(12)
        $tagTable  = pack('N', 1)        // Tag count
            . 'desc'                     // Tag signature
            . pack('N', $tagOffset)      // Offset to tag data
            . pack('N', $paddedSize);    // Tag size (4-byte aligned)

        $profile = $header . $tagTable . $descTag;

        // Patch profile size
        return pack('N', strlen($profile)) . substr($profile, 4);
    }

    /**
     * Builds a minimal profile with a caller-specified tag table and payload bytes.
     *
     * @param list<array{signature: string, offset: int, size: int}> $records
     */
    private function buildProfileWithCustomTagTable(array $records, string $payload): string
    {
        $header   = substr(IccFixtures::minimalProfile(), 0, 128);
        $tagTable = pack('N', count($records));

        foreach ($records as $record) {
            $tagTable .= $record['signature']
                . pack('N', $record['offset'])
                . pack('N', $record['size']);
        }

        $profile = $header . $tagTable . $payload;

        return pack('N', strlen($profile)) . substr($profile, 4);
    }

    /**
     * @param int    $sequence Sequence index of the ICC fragment.
     * @param int    $count    Total number of ICC fragments.
     * @param string $payload  Raw ICC fragment payload.
     */
    private function createSegment(int $sequence, int $count, string $payload): string
    {
        return 'ICC_PROFILE\0' . chr($sequence) . chr($count) . $payload;
    }

    /**
     * Tolerates non-zero upper 16 bits in rendering intent field.
     */
    #[Test]
    public function toleratesRenderingIntentNonZeroUpperBits(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Offset 64-67: rendering intent. Set upper 16 bits to 0xFFFF, keep lower=1
        $profile = substr_replace($profile, pack('N', 0xFFFF0001), 64, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates non-zero reserved bits (3..15) in profile flags field.
     */
    #[Test]
    public function toleratesProfileFlagsReservedBits(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Offset 44-47: profile flags. Set reserved bits 3..15
        $profile = substr_replace($profile, pack('N', 0x0000FFF8), 44, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates non-zero reserved bits (4..31) in device attributes lower word.
     */
    #[Test]
    public function toleratesDeviceAttributesReservedBits(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Offset 56-63: device attributes (8 bytes). Lower 32-bit word at offset 60-63.
        $profile = substr_replace($profile, pack('N', 0xFFFFFFF0), 60, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Tolerates a non-zero profile ID whose MD5 digest does not match the computed value.
     *
     * ICC.1:2022 §7.2.18 requires the MD5 to match, but real-world profiles from
     * Adobe and Apple often have stale or incorrect profile IDs after editing.
     */
    #[Test]
    public function toleratesProfileIdMd5Mismatch(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write a bogus non-zero profile ID at bytes 84..99
        $profile = substr_replace($profile, str_repeat("\xAB", 16), 84, 16);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNotNull($result['profileId']);
    }

    /**
     * Tolerates a PCS illuminant that deviates slightly from canonical D50 values.
     *
     * ICC.1:2022 §7.2.16 requires exact D50 (0.9642, 1.0, 0.8249), but
     * real-world profiles may have minor rounding differences.
     */
    #[Test]
    public function toleratesD50IlluminantDeviation(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Canonical D50 X = 0.9642 → s15Fixed16 = 0x0000F6D6
        // Slightly off: 0x0000F6D0 ≈ 0.9641 (within ±0.005 tolerance)
        $profile = substr_replace($profile, pack('N', 0x0000F6D0), 68, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertNotNull($result['illuminant']);
    }

    /**
     * Tolerates a vendor-specific profile class not in the ICC.1:2022 Table 18 allowed set.
     */
    #[Test]
    public function toleratesUnknownProfileClass(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write vendor class 'XVND' at offset 12 (PROFILE_CLASS)
        $profile = substr_replace($profile, 'XVND', IccTag::PROFILE_CLASS, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('XVND', $result['profileClass']);
    }

    /**
     * Tolerates a non-standard data colour space not in ICC.1:2022 Table 19.
     */
    #[Test]
    public function toleratesUnknownColorSpace(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write non-standard colour space 'UVWX' at offset 16 (COLOR_SPACE)
        $profile = substr_replace($profile, 'UVWX', IccTag::COLOR_SPACE, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('UVWX', $result['colorSpace']);
    }

    /**
     * Tolerates a PCS signature that is neither 'XYZ ' nor 'Lab '.
     */
    #[Test]
    public function toleratesUnknownPcsSignature(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write non-standard PCS 'Luv ' at offset 20 (PCS)
        $profile = substr_replace($profile, 'Luv ', IccTag::PCS, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Luv ', $result['pcs']);
    }

    /**
     * Tolerates a primary platform signature not in ICC.1:2022 Table 20.
     */
    #[Test]
    public function toleratesUnknownPrimaryPlatform(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write vendor platform 'LNUX' at offset 40 (PRIMARY_PLATFORM)
        $profile = substr_replace($profile, 'LNUX', IccTag::PRIMARY_PLATFORM, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('LNUX', $result['primaryPlatform']);
    }

    /**
     * Tolerates non-printable bytes in signature fields (creator, CMM, manufacturer, model).
     */
    #[Test]
    public function toleratesNonPrintableSignatureBytes(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Write non-printable bytes in profile creator at offset 80 (PROFILE_CREATOR)
        $profile = substr_replace($profile, "\x01\x02\x03\x04", IccTag::PROFILE_CREATOR, 4);

        // Write non-printable bytes in CMM type at offset 4 (CMM_TYPE)
        $profile = substr_replace($profile, "\x80\x81\x82\x83", IccTag::CMM_TYPE, 4);

        // Write non-printable bytes in device manufacturer at offset 48 (DEVICE_MANUFACTURER)
        $profile = substr_replace($profile, "\xF0\xF1\xF2\xF3", IccTag::DEVICE_MANUFACTURER, 4);

        // Write non-printable bytes in device model at offset 52 (DEVICE_MODEL)
        $profile = substr_replace($profile, "\xFE\xFD\xFC\xFB", IccTag::DEVICE_MODEL, 4);

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
    }

    /**
     * Rejects an ICC profile whose tag table count would overflow when multiplied by entry size.
     * A tag count of 0xFFFFFFFF (max uint32) times the 12-byte entry size wraps around on
     * 32-bit PHP, producing a falsely small table-end offset that bypasses bounds checking.
     */
    #[Test]
    public function rejectsTagCountThatOverflowsWhenMultipliedByEntrySize(): void
    {
        $header = str_pad(
            pack('N', 0)               // Profile size (placeholder, patched below)
                . str_repeat("\0", 4)  // Preferred CMM type
                . pack('N', 0x02400000) // Version 2.4.0
                . str_repeat("\0", 4)  // Device class
                . 'RGB '               // Color space
                . 'XYZ '              // PCS
                . str_repeat("\0", 12) // Date/time
                . 'acsp'              // Profile signature
                . str_repeat("\0", 52), // Remaining header fields
            128,
            "\0",
        );

        // Inject 0xFFFFFFFF as tag count: multiplying by 12 overflows on 32-bit PHP
        $profile = $header . pack('N', 0xFFFFFFFF);
        $profile = pack('N', strlen($profile)) . substr($profile, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects combined ICC segments whose cumulative size exceeds the configured limit.
     */
    #[Test]
    public function rejectsCombinedSegmentsExceedingMaxIccProfileSize(): void
    {
        $chunkA = str_repeat('A', 60);
        $chunkB = str_repeat('B', 60);

        $segments = [
            $this->createSegment(1, 2, $chunkA),
            $this->createSegment(2, 2, $chunkB),
        ];

        $decoder = new IccParser(maxIccProfileSize: 100);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1949);

        $decoder->decode(null, $segments);
    }

    /**
     * Parses a synthetic ICC profile containing extended tag coverage:
     * blackPoint, matrix columns, TRC curves, device descriptions,
     * luminance, and technology signature.
     */
    #[Test]
    public function decodeExtractsExpandedTagCoverage(): void
    {
        $profile = $this->buildExpandedProfile();

        $decoder = new IccParser();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);

        // XYZ tags
        self::assertNotNull($result['blackPoint']);
        self::assertEqualsWithDelta(0.0, $result['blackPoint']['x'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['blackPoint']['y'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['blackPoint']['z'], 0.001);

        self::assertNotNull($result['redMatrixColumn']);
        self::assertEqualsWithDelta(0.4361, $result['redMatrixColumn']['x'], 0.001);
        self::assertEqualsWithDelta(0.2225, $result['redMatrixColumn']['y'], 0.001);
        self::assertEqualsWithDelta(0.0139, $result['redMatrixColumn']['z'], 0.001);

        self::assertNotNull($result['greenMatrixColumn']);
        self::assertEqualsWithDelta(0.3851, $result['greenMatrixColumn']['x'], 0.001);
        self::assertEqualsWithDelta(0.7169, $result['greenMatrixColumn']['y'], 0.001);
        self::assertEqualsWithDelta(0.0971, $result['greenMatrixColumn']['z'], 0.001);

        self::assertNotNull($result['blueMatrixColumn']);
        self::assertEqualsWithDelta(0.1431, $result['blueMatrixColumn']['x'], 0.001);
        self::assertEqualsWithDelta(0.0606, $result['blueMatrixColumn']['y'], 0.001);
        self::assertEqualsWithDelta(0.7141, $result['blueMatrixColumn']['z'], 0.001);

        self::assertNotNull($result['luminance']);
        self::assertEqualsWithDelta(76.0365, $result['luminance']['x'], 0.01);
        self::assertEqualsWithDelta(80.0, $result['luminance']['y'], 0.01);
        self::assertEqualsWithDelta(87.1246, $result['luminance']['z'], 0.01);

        // TRC tags (parametric curve type 0: Y = X^gamma)
        self::assertNotNull($result['redTRC']);
        self::assertArrayHasKey('gamma', $result['redTRC']);
        self::assertEqualsWithDelta(2.2, $result['redTRC']['gamma'], 0.01);

        self::assertNotNull($result['greenTRC']);
        self::assertArrayHasKey('gamma', $result['greenTRC']);
        self::assertEqualsWithDelta(2.2, $result['greenTRC']['gamma'], 0.01);

        self::assertNotNull($result['blueTRC']);
        self::assertArrayHasKey('gamma', $result['blueTRC']);
        self::assertEqualsWithDelta(2.2, $result['blueTRC']['gamma'], 0.01);

        // Text tags
        self::assertSame('Test Manufacturer', $result['deviceMfgDesc']);
        self::assertSame('Test Model', $result['deviceModelDesc']);

        // Technology signature
        self::assertSame('CRT ', $result['technology']);

        // Viewing conditions (ICC.1:2022 §9.2.51 / §10.30)
        self::assertNotNull($result['viewingConditions']);
        self::assertEqualsWithDelta(19.6445, $result['viewingConditions']['illuminant']['x'], 0.001);
        self::assertEqualsWithDelta(20.0, $result['viewingConditions']['illuminant']['y'], 0.001);
        self::assertEqualsWithDelta(16.5330, $result['viewingConditions']['illuminant']['z'], 0.001);
        self::assertEqualsWithDelta(0.3457, $result['viewingConditions']['surround']['x'], 0.001);
        self::assertEqualsWithDelta(0.3585, $result['viewingConditions']['surround']['y'], 0.001);
        self::assertEqualsWithDelta(0.2958, $result['viewingConditions']['surround']['z'], 0.001);
        self::assertSame(1, $result['viewingConditions']['illuminantType']);

        // Measurement data (ICC.1:2022 §9.2.34 / §10.14)
        self::assertNotNull($result['measurement']);
        self::assertSame(1, $result['measurement']['observer']);
        self::assertEqualsWithDelta(0.9642, $result['measurement']['backing']['x'], 0.001);
        self::assertEqualsWithDelta(1.0000, $result['measurement']['backing']['y'], 0.001);
        self::assertEqualsWithDelta(0.8249, $result['measurement']['backing']['z'], 0.001);
        self::assertSame(1, $result['measurement']['geometry']);
        self::assertEqualsWithDelta(0.0, $result['measurement']['flare'], 0.001);
        self::assertSame(1, $result['measurement']['illuminant']);
    }

    /**
     * Builds a synthetic ICC v4 profile with expanded tag coverage for testing.
     */
    private function buildExpandedProfile(): string
    {
        $header = pack('N', 0)
            . str_repeat("\0", 4)
            . pack('N', 0x04200000)      // Version 4.2.0
            . str_repeat("\0", 4)
            . 'RGB '
            . 'XYZ '
            . str_repeat("\0", 12)
            . 'acsp'
            . str_repeat("\0", 24)
            . pack('N', 0)
            . pack('N', 0x0000F6D6)
            . pack('N', 0x00010000)
            . pack('N', 0x0000D32D)
            . str_repeat("\0", 4)
            . str_repeat("\0", 16)
            . str_repeat("\0", 28);

        $header = str_pad($header, 128, "\0");

        // Build tag payloads

        $encodeS15 = static function (float $v): string {
            $raw = (int) round($v * 65536.0);
            if ($raw < 0) {
                $raw += 0x100000000;
            }

            return pack('N', $raw);
        };

        // XYZ tag helper: 'XYZ ' + reserved(4) + 3 x s15Fixed16Number
        $buildXyz = static function (float $x, float $y, float $z) use ($encodeS15): string {
            return "XYZ \0\0\0\0" . $encodeS15($x) . $encodeS15($y) . $encodeS15($z);
        };

        $bkptData = $buildXyz(0.0, 0.0, 0.0);
        $rXyzData = $buildXyz(0.4361, 0.2225, 0.0139);
        $gXyzData = $buildXyz(0.3851, 0.7169, 0.0971);
        $bXyzData = $buildXyz(0.1431, 0.0606, 0.7141);
        $lumiData = $buildXyz(76.0365, 80.0, 87.1246);

        // Parametric curve type 0: Y = X^gamma
        // 'para' + reserved(4) + functionType(2) + reserved(2) + gamma s15Fixed16
        $gamma22  = (int) round(2.2 * 65536.0);
        $paraData = "para\0\0\0\0" . pack('n', 0) . "\0\0" . pack('N', $gamma22);
        $rTrcData = $paraData;
        $gTrcData = $paraData;
        $bTrcData = $paraData;

        // mluc text helper for dmnd/dmdd
        $buildMluc = static function (string $text): string {
            $utf16 = '';
            for ($i = 0; $i < strlen($text); ++$i) {
                $utf16 .= "\x00" . $text[$i];
            }

            $stringOffset = 16 + 12;
            $mluc         = 'mluc'
                . pack('N', 0)
                . pack('N', 1)
                . pack('N', 12)
                . 'enUS'
                . pack('N', strlen($utf16))
                . pack('N', $stringOffset)
                . $utf16;

            $padded = (int) (ceil(strlen($mluc) / 4) * 4);

            return str_pad($mluc, $padded, "\0");
        };

        $dmndData = $buildMluc('Test Manufacturer');
        $dmddData = $buildMluc('Test Model');

        // Technology signature: 'sig ' + reserved(4) + 4-byte signature
        $techData = "sig \0\0\0\0CRT ";

        // Viewing conditions: 'view' + reserved + illuminant XYZ + surround XYZ + illuminantType
        $viewData = "view\0\0\0\0"
            . $encodeS15(19.6445) . $encodeS15(20.0) . $encodeS15(16.5330)
            . $encodeS15(0.3457) . $encodeS15(0.3585) . $encodeS15(0.2958)
            . pack('N', 1);

        // Measurement: 'meas' + reserved + observer + backing XYZ + geometry + flare(u16Fixed16) + illuminant
        $measData = "meas\0\0\0\0"
            . pack('N', 1)
            . $encodeS15(0.9642) . $encodeS15(1.0) . $encodeS15(0.8249)
            . pack('N', 1)
            . pack('N', 0)
            . pack('N', 1);

        // Description tag (mluc)
        $descData = $buildMluc('Expanded Test Profile');

        // Collect all tags
        $tags = [
            ['sig' => 'desc', 'data' => $descData],
            ['sig' => 'bkpt', 'data' => $bkptData],
            ['sig' => 'rXYZ', 'data' => $rXyzData],
            ['sig' => 'gXYZ', 'data' => $gXyzData],
            ['sig' => 'bXYZ', 'data' => $bXyzData],
            ['sig' => 'lumi', 'data' => $lumiData],
            ['sig' => 'rTRC', 'data' => $rTrcData],
            ['sig' => 'gTRC', 'data' => $gTrcData],
            ['sig' => 'bTRC', 'data' => $bTrcData],
            ['sig' => 'dmnd', 'data' => $dmndData],
            ['sig' => 'dmdd', 'data' => $dmddData],
            ['sig' => 'tech', 'data' => $techData],
            ['sig' => 'view', 'data' => $viewData],
            ['sig' => 'meas', 'data' => $measData],
        ];

        $tagCount  = count($tags);
        $tableSize = 4 + ($tagCount * 12);

        // Tag data starts right after header + tag table (always 4-byte aligned)
        $dataOffset = 128 + $tableSize;

        // Build tag table and concatenate data
        $tagTable = pack('N', $tagCount);
        $tagData  = '';
        $cursor   = $dataOffset;

        foreach ($tags as $tag) {
            $paddedSize = (int) (ceil(strlen($tag['data']) / 4) * 4);
            $padded     = str_pad($tag['data'], $paddedSize, "\0");

            $tagTable .= $tag['sig']
                . pack('N', $cursor)
                . pack('N', $paddedSize);

            $tagData .= $padded;
            $cursor += $paddedSize;
        }

        // Pad between header+tagTable and tag data if needed
        $padding = $dataOffset - (128 + $tableSize);
        $profile = $header . $tagTable . str_repeat("\0", $padding) . $tagData;

        return pack('N', strlen($profile)) . substr($profile, 4);
    }
}
