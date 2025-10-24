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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function intdiv;
use function strlen;
use function substr;

/**
 * @covers \MagicSunday\ImageMeta\Parse\Icc\IccDecoder
 */
#[CoversClass(IccDecoder::class)]
final class IccDecoderTest extends TestCase
{
    #[Test]
    public function testDecodeExtractsHeaderFields(): void
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
    public function testDecodeReassemblesSegments(): void
    {
        $profile = IccFixtures::minimalProfile();

        $half = intdiv(strlen($profile) + 1, 2);
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
    public function testDecodeReturnsNullForTruncatedData(): void
    {
        $decoder = new IccDecoder();

        self::assertNull($decoder->decode('short'));
        self::assertNull($decoder->decode(null, [$this->createSegment(1, 2, 'payload')]));
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
