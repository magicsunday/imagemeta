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
 * Exercises the Capture value object for timestamp and environmental fields.
 * It verifies DateTime storage alongside temperature, humidity, and pressure data.
 * The suite checks optional fields like water depth and acceleration vectors.
 * This ensures capture context metadata remains intact for consumers.
 */
#[CoversClass(Capture::class)]
final class CaptureTest extends TestCase
{
    /**
     * Stores the capture timestamp when provided.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithDateTime(): void
    {
        $dateTime = new DateTimeImmutable('2024-01-15 14:30:00');

        $capture = new Capture(
            dateTime: $dateTime,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
        );

        self::assertSame($dateTime, $capture->dateTime);
    }

    /**
     * Stores environmental capture data values.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithEnvironmentalData(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: 22.5,
            humidityPercent: 65.0,
            pressureHPa: 1013.25,
            waterDepthM: 5.2,
            accelerationMs2: 9.8,
            cameraElevationAngleDeg: 15.5,
        );

        self::assertSame(22.5, $capture->temperatureC);
        self::assertSame(65.0, $capture->humidityPercent);
        self::assertSame(1013.25, $capture->pressureHPa);
        self::assertSame(5.2, $capture->waterDepthM);
        self::assertSame(9.8, $capture->accelerationMs2);
        self::assertSame(15.5, $capture->cameraElevationAngleDeg);
    }

    /**
     * Allows capture fields to be omitted.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
        );

        self::assertNull($capture->dateTime);
        self::assertNull($capture->temperatureC);
        self::assertNull($capture->humidityPercent);
        self::assertNull($capture->pressureHPa);
        self::assertNull($capture->waterDepthM);
        self::assertNull($capture->accelerationMs2);
        self::assertNull($capture->cameraElevationAngleDeg);
    }

    /**
     * Stores large water depth values.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithDeepWaterDepth(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: 100.5,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
        );

        self::assertSame(100.5, $capture->waterDepthM);
    }

    /**
     * Stores zero water depth values.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithZeroWaterDepth(): void
    {
        $capture = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: 0.0,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
        );

        self::assertSame(0.0, $capture->waterDepthM);
    }

    /**
     * Stores a range of acceleration magnitudes.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithVariousAccelerationMagnitudes(): void
    {
        // Near gravity
        $capture1 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: 9.81,
            cameraElevationAngleDeg: null,
        );

        self::assertSame(9.81, $capture1->accelerationMs2);

        // Zero acceleration (free fall or stationary)
        $capture2 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: 0.0,
            cameraElevationAngleDeg: null,
        );

        self::assertSame(0.0, $capture2->accelerationMs2);

        // High acceleration (vehicle, impact)
        $capture3 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: 50.0,
            cameraElevationAngleDeg: null,
        );

        self::assertSame(50.0, $capture3->accelerationMs2);
    }

    /**
     * Stores camera elevation angles across expected ranges.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithVariousCameraElevationAngles(): void
    {
        // Upward tilt
        $capture1 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: 45.0,
        );

        self::assertSame(45.0, $capture1->cameraElevationAngleDeg);

        // Downward tilt
        $capture2 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: -30.0,
        );

        self::assertSame(-30.0, $capture2->cameraElevationAngleDeg);

        // Level horizon
        $capture3 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: 0.0,
        );

        self::assertSame(0.0, $capture3->cameraElevationAngleDeg);

        // Extreme angles (near vertical)
        $capture4 = new Capture(
            dateTime: null,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: 89.5,
        );

        self::assertSame(89.5, $capture4->cameraElevationAngleDeg);
    }

    /**
     * Stores EXIF 3.0 environmental tags together in the value object.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithAllExif30EnvironmentalTags(): void
    {
        $capture = new Capture(
            dateTime: new DateTimeImmutable('2024-11-07 15:30:00'),
            temperatureC: 15.5,
            humidityPercent: 85.0,
            pressureHPa: 1020.0,
            waterDepthM: 25.3,
            accelerationMs2: 10.2,
            cameraElevationAngleDeg: -15.5,
        );

        self::assertInstanceOf(DateTimeImmutable::class, $capture->dateTime);
        self::assertSame(15.5, $capture->temperatureC);
        self::assertSame(85.0, $capture->humidityPercent);
        self::assertSame(1020.0, $capture->pressureHPa);
        self::assertSame(25.3, $capture->waterDepthM);
        self::assertSame(10.2, $capture->accelerationMs2);
        self::assertSame(-15.5, $capture->cameraElevationAngleDeg);
    }
}
