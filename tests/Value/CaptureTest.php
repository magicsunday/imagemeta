<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Value\Capture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Capture value object.
 */
#[CoversClass(Capture::class)]
final class CaptureTest extends TestCase
{
    #[Test]
    public function constructsWithDateTime(): void
    {
        $dateTime = new DateTimeImmutable('2024-01-15 14:30:00');

        $capture = new Capture(
            dateTime: $dateTime,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            batteryLevelPercent: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
            selfTimerModeSeconds: null,
        );

        self::assertSame($dateTime, $capture->dateTime);
    }

    #[Test]
    public function constructsWithEnvironmentalData(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: 22.5,
            humidityPercent: 65.0,
            pressureHPa: 1013.25,
            batteryLevelPercent: 85.0,
            waterDepthM: 5.2,
            accelerationMs2: 9.8,
            cameraElevationAngleDeg: 15.5,
            selfTimerModeSeconds: 10,
        );

        self::assertSame(22.5, $capture->temperatureC);
        self::assertSame(65.0, $capture->humidityPercent);
        self::assertSame(1013.25, $capture->pressureHPa);
        self::assertSame(85.0, $capture->batteryLevelPercent);
        self::assertSame(5.2, $capture->waterDepthM);
        self::assertSame(9.8, $capture->accelerationMs2);
        self::assertSame(15.5, $capture->cameraElevationAngleDeg);
        self::assertSame(10, $capture->selfTimerModeSeconds);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            batteryLevelPercent: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
            selfTimerModeSeconds: null,
        );

        self::assertNull($capture->dateTime);
        self::assertNull($capture->temperatureC);
        self::assertNull($capture->humidityPercent);
        self::assertNull($capture->pressureHPa);
        self::assertNull($capture->batteryLevelPercent);
        self::assertNull($capture->waterDepthM);
        self::assertNull($capture->accelerationMs2);
        self::assertNull($capture->cameraElevationAngleDeg);
        self::assertNull($capture->selfTimerModeSeconds);
    }
}
