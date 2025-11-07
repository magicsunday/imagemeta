<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Motion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Motion value object.
 */
#[CoversClass(Motion::class)]
final class MotionTest extends TestCase
{
    #[Test]
    public function constructsWithAcceleration(): void
    {
        $motion = new Motion(
            accelX: 0.5,
            accelY: -0.2,
            accelZ: 9.8,
        );

        self::assertSame(0.5, $motion->accelX);
        self::assertSame(-0.2, $motion->accelY);
        self::assertSame(9.8, $motion->accelZ);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $motion = new Motion(
            accelX: null,
            accelY: null,
            accelZ: null,
        );

        self::assertNull($motion->accelX);
        self::assertNull($motion->accelY);
        self::assertNull($motion->accelZ);
    }
}
