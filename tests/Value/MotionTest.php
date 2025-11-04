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
    public function constructsWithAngles(): void
    {
        $motion = new Motion(
            rollDeg: 2.5,
            pitchDeg: -10.2,
            yawDeg: 45.5,
            accelX: null,
            accelY: null,
            accelZ: null,
            gyroX: null,
            gyroY: null,
            gyroZ: null,
        );

        self::assertSame(2.5, $motion->rollDeg);
        self::assertSame(-10.2, $motion->pitchDeg);
        self::assertSame(45.5, $motion->yawDeg);
    }

    #[Test]
    public function constructsWithAcceleration(): void
    {
        $motion = new Motion(
            rollDeg: null,
            pitchDeg: null,
            yawDeg: null,
            accelX: 0.5,
            accelY: -0.2,
            accelZ: 9.8,
            gyroX: null,
            gyroY: null,
            gyroZ: null,
        );

        self::assertSame(0.5, $motion->accelX);
        self::assertSame(-0.2, $motion->accelY);
        self::assertSame(9.8, $motion->accelZ);
    }

    #[Test]
    public function constructsWithGyroscope(): void
    {
        $motion = new Motion(
            rollDeg: null,
            pitchDeg: null,
            yawDeg: null,
            accelX: null,
            accelY: null,
            accelZ: null,
            gyroX: 1.5,
            gyroY: 2.3,
            gyroZ: -0.8,
        );

        self::assertSame(1.5, $motion->gyroX);
        self::assertSame(2.3, $motion->gyroY);
        self::assertSame(-0.8, $motion->gyroZ);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $motion = new Motion(
            rollDeg: null,
            pitchDeg: null,
            yawDeg: null,
            accelX: null,
            accelY: null,
            accelZ: null,
            gyroX: null,
            gyroY: null,
            gyroZ: null,
        );

        self::assertNull($motion->rollDeg);
        self::assertNull($motion->accelX);
        self::assertNull($motion->gyroX);
    }
}
