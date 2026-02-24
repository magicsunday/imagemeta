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
 * Exercises the Motion value object for acceleration readings on all axes.
 * It verifies positive and negative acceleration values are preserved.
 * The suite covers null values to ensure optional sensor data remains nullable.
 * This keeps motion metadata consistent for analysis and display.
 */
#[CoversClass(Motion::class)]
final class MotionTest extends TestCase
{
    /**
     * Stores acceleration values for all axes.
     * It confirms the object preserves the supplied metadata.
     */
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

    /**
     * Accepts null acceleration values.
     * It ensures missing or invalid inputs yield no value.
     */
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

    /**
     * Stores gravity-aligned acceleration vectors.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithGravityVector(): void
    {
        // Typical gravity: nearly zero on X and Y, ~9.8 on Z
        $motion = new Motion(
            accelX: 0.0,
            accelY: 0.0,
            accelZ: 9.81,
        );

        self::assertSame(0.0, $motion->accelX);
        self::assertSame(0.0, $motion->accelY);
        self::assertSame(9.81, $motion->accelZ);
    }

    /**
     * Stores negative acceleration values.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithNegativeAcceleration(): void
    {
        $motion = new Motion(
            accelX: -1.5,
            accelY: -2.3,
            accelZ: -9.8,
        );

        self::assertSame(-1.5, $motion->accelX);
        self::assertSame(-2.3, $motion->accelY);
        self::assertSame(-9.8, $motion->accelZ);
    }

    /**
     * Stores large acceleration magnitudes.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithLargeAcceleration(): void
    {
        // Example: crash test or extreme sports
        $motion = new Motion(
            accelX: 150.0,
            accelY: -200.0,
            accelZ: 50.0,
        );

        self::assertSame(150.0, $motion->accelX);
        self::assertSame(-200.0, $motion->accelY);
        self::assertSame(50.0, $motion->accelZ);
    }

    /**
     * Stores small acceleration magnitudes.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithSmallAcceleration(): void
    {
        $motion = new Motion(
            accelX: 0.001,
            accelY: -0.002,
            accelZ: 0.003,
        );

        self::assertSame(0.001, $motion->accelX);
        self::assertSame(-0.002, $motion->accelY);
        self::assertSame(0.003, $motion->accelZ);
    }

    /**
     * Stores mixed-sign acceleration vectors.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithMixedSignAcceleration(): void
    {
        $motion = new Motion(
            accelX: 5.0,
            accelY: -3.5,
            accelZ: 9.8,
        );

        self::assertSame(5.0, $motion->accelX);
        self::assertSame(-3.5, $motion->accelY);
        self::assertSame(9.8, $motion->accelZ);
    }

    /**
     * Stores partial acceleration vectors with nullable axes.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function constructsWithPartialNullValues(): void
    {
        $motion = new Motion(
            accelX: 1.0,
            accelY: null,
            accelZ: 9.8,
        );

        self::assertSame(1.0, $motion->accelX);
        self::assertNull($motion->accelY);
        self::assertSame(9.8, $motion->accelZ);
    }
}
