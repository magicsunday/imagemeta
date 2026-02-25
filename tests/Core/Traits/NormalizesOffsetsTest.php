<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Traits;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

/**
 * Verifies overflow guards in the NormalizesOffsets trait.
 * It confirms that arithmetic on relative offsets does not silently overflow.
 * The trait must reject combinations where base + delta would exceed PHP_INT_MAX.
 * This protects 32-bit platforms and extreme offset values from producing corrupt results.
 */
#[CoversClass(BoundsError::class)]
#[UsesTrait(NormalizesOffsets::class)]
final class NormalizesOffsetsTest extends TestCase
{
    /**
     * Passes a base of PHP_INT_MAX and a delta of 1 to normalizeRelativeOffset.
     * On any platform this sum would overflow the signed integer range.
     * It expects a BoundsError to be raised before the arithmetic is performed.
     */
    #[Test]
    public function normalizeRelativeOffsetThrowsBoundsErrorOnIntegerOverflow(): void
    {
        $stub = new class {
            use NormalizesOffsets;

            protected function offsetLimit(): int
            {
                return PHP_INT_MAX;
            }

            public function resolveRelativeOffset(int|UInt64 $offset, int $base, string $message): int
            {
                return $this->normalizeRelativeOffset($offset, $base, $message);
            }
        };

        $this->expectException(BoundsError::class);

        $stub->resolveRelativeOffset(1, PHP_INT_MAX, 'overflow test');
    }
}
