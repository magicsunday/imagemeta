<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Icc;

use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Tests\Fixtures\Icc\IccFixtures;
use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function intdiv;
use function strlen;
use function substr;
use function substr_replace;

/**
 * ICC decoder tests.
 */
#[UsesClass(IccRenderingIntent::class)]
#[CoversClass(IccDecoder::class)]
final class IccDecoderTest extends TestCase
{
    #[Test]
    public function decodeExtractsHeaderFields(): void
    {
        $profile = IccFixtures::minimalProfile();

        $decoder = new IccDecoder();
        $result  = $decoder->decode($profile);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('4.2.1', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertSame('00112233445566778899AABBCCDDEEFF', $result['profileId']);
    }

    #[Test]
    public function decodeReassemblesSegments(): void
    {
        $profile = IccFixtures::minimalProfile();

        $half     = intdiv(strlen($profile) + 1, 2);
        $segments = [
            $this->createSegment(1, 2, substr($profile, 0, $half)),
            $this->createSegment(2, 2, substr($profile, $half)),
        ];

        $decoder = new IccDecoder();
        $result  = $decoder->decode(null, $segments);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('4.2.1', $result['version']);
        self::assertSame('XYZ ', $result['pcs']);
        self::assertSame('Media-Relative Colorimetric', $result['renderingIntent']);
        self::assertSame('00112233445566778899AABBCCDDEEFF', $result['profileId']);
    }

    #[Test]
    public function decodeReturnsNullForTruncatedData(): void
    {
        $decoder = new IccDecoder();

        self::assertNull($decoder->decode('short'));
        self::assertNull($decoder->decode(null, [$this->createSegment(1, 2, 'payload')]));
    }

    #[Test]
    public function decodeHandlesOutOfOrderSegments(): void
    {
        $profile = IccFixtures::minimalProfile();

        $half     = intdiv(strlen($profile) + 1, 2);
        $segments = [
            $this->createSegment(2, 2, substr($profile, $half)),
            $this->createSegment(1, 2, substr($profile, 0, $half)),
        ];

        $decoder = new IccDecoder();
        $result  = $decoder->decode(null, $segments);

        self::assertNotNull($result);
        self::assertSame('Test Profile', $result['description']);
        self::assertSame('4.2.1', $result['version']);
    }

    #[Test]
    public function decodeRejectsIncompleteSegmentSequences(): void
    {
        $profile = IccFixtures::minimalProfile();

        $third    = intdiv(strlen($profile), 3);
        $segments = [
            $this->createSegment(1, 3, substr($profile, 0, $third)),
            $this->createSegment(3, 3, substr($profile, $third * 2)),
        ];

        $decoder = new IccDecoder();

        self::assertNull($decoder->decode(null, $segments));
    }

    #[Test]
    public function decodeExtractsLegacyVersionEncoding(): void
    {
        $profile = IccFixtures::minimalProfile();
        $profile = substr_replace($profile, chr(0x02), 8, 1);
        $profile = substr_replace($profile, chr(0x13), 9, 1);

        $decoder = new IccDecoder();
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
