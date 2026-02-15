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
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Tests\Fixtures\Icc\IccFixtures;
use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function intdiv;
use function pack;
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
#[CoversClass(IccParser::class)]
final class IccParserTest extends TestCase
{
    /**
     * Decodes a full ICC profile and extracts key header fields.
     * This verifies version parsing, PCS detection, rendering intent, and profile ID formatting.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function decodeReturnsNullForTruncatedData(): void
    {
        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode('short');
    }

    /**
     * Feeds out-of-order ICC segments to the decoder.
     * This confirms the decoder sorts fragments before reconstruction.
     *
     * @return void
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
     *
     * @return void
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
     * Rejects profiles whose declared size is not 4-byte aligned.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsMisalignedProfileSize(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, pack('N', 243), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects tag tables with misaligned tag offsets.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsMisalignedTagOffset(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, pack('N', 145), 136, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects tag tables with misaligned tag sizes.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsMisalignedTagSize(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, pack('N', 99), 140, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects non-NULL padding after the last tag data block.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsNonNullPaddingAfterLastTag(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile .= "\x01" . str_repeat("\0", 3);
        $profile = substr_replace($profile, pack('N', strlen($profile)), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects profiles where the declared size is below the 128-byte header minimum.
     *
     * @return void
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
     * Rejects profiles where the declared size does not match the actual payload length.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsProfileSizeMismatch(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Append 4 extra bytes without updating profileSize
        $profile .= str_repeat("\0", 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects truncated profiles where the declared size exceeds available data.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsTruncatedProfile(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Truncate to 200 bytes while profileSize claims 244
        $profile = substr($profile, 0, 200);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects profiles that declare the 128-byte header size but omit the tag-count field.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsHeaderOnlyProfileWithoutTagTableBytes(): void
    {
        $profile = substr(IccFixtures::minimalProfile(), 0, 128);
        $profile = substr_replace($profile, pack('N', 128), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Builds a profile with two tags sharing the same signature.
     * Confirms the parser rejects duplicate tag signatures per ICC.1:2022 §7.3.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsDuplicateTagSignatures(): void
    {
        $profile = IccFixtures::minimalProfile();

        // Current profile: 1 tag (desc). Add a second 'desc' entry:
        // Increase tag count to 2, insert another 12-byte record, adjust offsets/size.
        // Header + tag_count(4) + 2*record(24) = 156. desc data = 100 bytes.
        // Total = 256 bytes.
        $header = substr($profile, 0, 128);
        // Set tag count = 2
        $tagTable = pack('N', 2);
        // desc #1 at offset 156 (header+4+24), size 100
        $tagTable .= 'desc' . pack('N', 156) . pack('N', 100);
        // desc #2 at offset 156 (same, duplicate signature)
        $tagTable .= 'desc' . pack('N', 156) . pack('N', 100);
        // desc data (100 bytes)
        $descData = substr($profile, 144, 100);
        // Assemble and update profile size
        $newProfile = $header . $tagTable . $descData;
        $newProfile = substr_replace($newProfile, pack('N', strlen($newProfile)), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($newProfile);
    }

    /**
     * Builds a profile with two tags sharing the same offset but different sizes.
     * Confirms the parser rejects mismatched shared-offset sizes per ICC.1:2022 §7.3.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsSharedOffsetSizeMismatch(): void
    {
        $profile = IccFixtures::minimalProfile();

        // 2 tags with different signatures but same offset, different sizes.
        $header   = substr($profile, 0, 128);
        $tagTable = pack('N', 2);
        // 'desc' at offset 156, size 100
        $tagTable .= 'desc' . pack('N', 156) . pack('N', 100);
        // 'cprt' at offset 156, size 96 (different!)
        $tagTable .= 'cprt' . pack('N', 156) . pack('N', 96);
        // desc data (100 bytes)
        $descData   = substr($profile, 144, 100);
        $newProfile = $header . $tagTable . $descData;
        $newProfile = substr_replace($newProfile, pack('N', strlen($newProfile)), 0, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($newProfile);
    }

    /**
     * Accepts a valid tag table with contiguous non-overlapping data ranges.
     *
     * ICC.1:2022 §7.3 allows distinct tags when ranges are disjoint and contiguous.
     *
     * @return void
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
     * Rejects partially overlapping tag data ranges.
     *
     * ICC.1:2022 §7.3 forbids partial overlap between distinct tag data elements.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsPartiallyOverlappingTagDataRanges(): void
    {
        $profile = $this->buildProfileWithCustomTagTable(
            [
                ['signature' => 'desc', 'offset' => 156, 'size' => 8],
                ['signature' => 'cprt', 'offset' => 160, 'size' => 8],
            ],
            'abcdefghijkl',
        );

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Rejects gaps between tag data elements.
     *
     * ICC.1:2022 §7.3 requires a contiguous tag data sequence.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsGapBetweenTagDataElements(): void
    {
        $profile = $this->buildProfileWithCustomTagTable(
            [
                ['signature' => 'desc', 'offset' => 156, 'size' => 4],
                ['signature' => 'cprt', 'offset' => 164, 'size' => 4],
            ],
            'ABCD    WXYZ',
        );

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * Accepts shared tag data when offset and size are identical.
     *
     * ICC.1:2022 §7.3 allows aliasing only for exactly matching data elements.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * Rejects mluc payloads with non-zero reserved bytes in the type header.
     * ICC.1:2022 §10.1 and Table 54: bytes 4..7 must be zero.
     *
     * @return void
     */
    #[Test]
    public function rejectsMlucTagWithNonZeroTypeReservedBytes(): void
    {
        $profile = $this->buildMlucProfile("\x00\x48\x00\x69");

        $tagDataOffset = 128 + 4 + 12; // header + tagCount + one tag record
        $profile       = substr_replace($profile, "\0\0\0\x01", $tagDataOffset + 4, 4);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('reserved bytes 4..7');

        $decoder->decode($profile);
    }

    /**
     * Multi-record mluc with reordered records yields same selected output.
     *
     * The parser must select deterministically by locale, not by record order.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * GH-824: Rejects profiles with non-zero reserved bytes in version field.
     * ICC.1:2022 §7.2.4: bytes 10-11 must be 0x00.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsNonZeroVersionReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set byte 10 to non-zero
        $profile = substr_replace($profile, chr(0x01), 10, 1);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * GH-824: Rejects profiles with non-zero reserved byte 11 in version field.
     * ICC.1:2022 §7.2.4: bytes 10-11 must be 0x00.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsNonZeroVersionReservedByte11(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set byte 11 to non-zero
        $profile = substr_replace($profile, chr(0xFF), 11, 1);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * GH-1116: Rejects profiles with both non-zero reserved bytes in version field.
     * ICC.1:2022 §7.2.4: bytes 10-11 must be 0x00.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsBothNonZeroVersionReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, "\x01\xFF", 10, 2);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * GH-825: Rejects textType tags without trailing NUL byte.
     * ICC.1:2022 §10.24: textType must be NUL-terminated.
     *
     * @return void
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
     * GH-825: Rejects textType tags with non-ASCII bytes.
     * ICC.1:2022 §10.24: textType must contain only 7-bit ASCII (bytes <= 0x7F).
     *
     * @return void
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
     * GH-825: Accepts valid textType tags with 7-bit ASCII and NUL termination.
     * ICC.1:2022 §10.24: textType with valid 7-bit ASCII.
     *
     * @return void
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
     * GH-1115: Rejects textType tags with non-zero reserved bytes in the type header.
     * ICC.1:2022 §10.1 and §10.24: bytes 4..7 must be zero.
     *
     * @return void
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
     * GH-831: Rejects profiles with non-zero reserved header bytes.
     * ICC.1:2022 §7.2.19: bytes 100-127 must be zero.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsNonZeroHeaderReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set byte 100 to non-zero
        $profile = substr_replace($profile, chr(0x01), 100, 1);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * GH-831: Rejects profiles with non-zero reserved header byte at position 127.
     * ICC.1:2022 §7.2.19: bytes 100-127 must be zero.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsNonZeroHeaderReservedByte127(): void
    {
        $profile = IccFixtures::minimalProfile();
        // Set byte 127 (last reserved byte) to non-zero
        $profile = substr_replace($profile, chr(0xFF), 127, 1);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
    }

    /**
     * GH-1117: Rejects profiles with multiple non-zero reserved header bytes.
     * ICC.1:2022 §7.2.19: bytes 100-127 must be zero.
     *
     * @return void
     */
    #[Test]
    public function decodeRejectsMultipleNonZeroHeaderReservedBytes(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, "\xAA\x55\x01", 110, 3);

        $decoder = new IccParser();

        $this->expectException(ParseError::class);
        $decoder->decode($profile);
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
     * GH-834: Accepts valid desc tag with NUL-terminated 7-bit ASCII.
     * ICC spec: desc ASCII string must be NUL-terminated and 7-bit ASCII.
     *
     * @return void
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
     * GH-834: Rejects desc tags with non-ASCII bytes.
     * ICC spec: desc ASCII string must contain only 7-bit ASCII (bytes <= 0x7F).
     *
     * @return void
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
     * GH-834: Rejects desc tags without trailing NUL byte.
     * ICC spec: desc ASCII string must be NUL-terminated.
     *
     * @return void
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
     * GH-834: Rejects desc tags with length exceeding available data.
     * ICC spec: asciiLength must not exceed available payload.
     *
     * @return void
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
     * GH-1115: Rejects desc tags with non-zero reserved bytes in the type header.
     * ICC.1:2022 §10.1: bytes 4..7 must be zero.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * @param string                                                 $payload
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
}
