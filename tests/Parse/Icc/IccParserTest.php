<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Icc;

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
        self::assertSame('4.2.1', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertSame('00112233445566778899AABBCCDDEEFF', $result['profileId']);
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
        self::assertSame('4.2.1', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertSame('00112233445566778899AABBCCDDEEFF', $result['profileId']);
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

        self::assertNull($decoder->decode('short'));
        self::assertNull($decoder->decode(null, [$this->createSegment(1, 2, 'payload')]));
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
        self::assertSame('4.2.1', $result['version']);
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

        self::assertNull($decoder->decode(null, $segments));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($profile));
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

        self::assertNull($decoder->decode($newProfile));
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

        self::assertNull($decoder->decode($newProfile));
    }

    /**
     * Modifies the version bytes to an older ICC encoding.
     * This verifies legacy version parsing uses the correct byte layout.
     *
     * @return void
     */
    #[Test]
    public function decodeExtractsLegacyVersionEncoding(): void
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
     * @param int    $sequence Sequence index of the ICC fragment.
     * @param int    $count    Total number of ICC fragments.
     * @param string $payload  Raw ICC fragment payload.
     */
    private function createSegment(int $sequence, int $count, string $payload): string
    {
        return 'ICC_PROFILE\0' . chr($sequence) . chr($count) . $payload;
    }
}
