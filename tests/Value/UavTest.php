<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Uav (UAV/Drone) value object.
 */
#[CoversClass(Uav::class)]
final class UavTest extends TestCase
{
    #[Test]
    public function constructsWithAircraftInfo(): void
    {
        $uav = new Uav(
            manufacturer: 'DJI',
            model: 'Mavic 3',
            flightYaw: null,
            flightPitch: null,
            flightRoll: null,
            gimbalYaw: null,
            gimbalPitch: null,
            gimbalRoll: null,
        );

        self::assertSame('DJI', $uav->manufacturer);
        self::assertSame('Mavic 3', $uav->model);
    }

    #[Test]
    public function constructsWithFlightAngles(): void
    {
        $uav = new Uav(
            manufacturer: null,
            model: null,
            flightYaw: 45.5,
            flightPitch: -10.2,
            flightRoll: 2.5,
            gimbalYaw: 0.0,
            gimbalPitch: -90.0,
            gimbalRoll: 0.0,
        );

        self::assertSame(45.5, $uav->flightYaw);
        self::assertSame(-10.2, $uav->flightPitch);
        self::assertSame(2.5, $uav->flightRoll);
        self::assertSame(0.0, $uav->gimbalYaw);
        self::assertSame(-90.0, $uav->gimbalPitch);
        self::assertSame(0.0, $uav->gimbalRoll);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $uav = new Uav(
            manufacturer: null,
            model: null,
            flightYaw: null,
            flightPitch: null,
            flightRoll: null,
            gimbalYaw: null,
            gimbalPitch: null,
            gimbalRoll: null,
        );

        self::assertNull($uav->manufacturer);
        self::assertNull($uav->model);
        self::assertNull($uav->flightYaw);
        self::assertNull($uav->flightPitch);
        self::assertNull($uav->flightRoll);
        self::assertNull($uav->gimbalYaw);
        self::assertNull($uav->gimbalPitch);
        self::assertNull($uav->gimbalRoll);
    }
}
