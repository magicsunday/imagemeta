<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use DateTimeImmutable;

/**
 * Holds capture conditions and environmental sensor data.
 *
 * EXIF 3.0 §4.6.6 introduces environmental tags for recording capture conditions:
 * - Temperature (0x9400): Ambient temperature in Celsius
 * - Humidity (0x9401): Relative humidity percentage
 * - Pressure (0x9402): Atmospheric pressure in hPa
 * - WaterDepth (0x9403): Depth below water surface in metres
 * - Acceleration (0x9404): Camera acceleration magnitude in m/s²
 * - CameraElevationAngle (0x9405): Elevation angle relative to horizon in degrees
 */
final readonly class Capture
{
    /**
     * Creates a capture conditions metadata value object.
     *
     * @param DateTimeImmutable|null $dateTime                Capture timestamp.
     * @param float|null             $temperatureC            Recorded temperature in Celsius (EXIF 3.0 §4.6.6).
     * @param float|null             $humidityPercent         Relative humidity percentage (EXIF 3.0 §4.6.6).
     * @param float|null             $pressureHPa             Ambient pressure in hPa (EXIF 3.0 §4.6.6).
     * @param float|null             $waterDepthM             Water depth in metres (EXIF 3.0 §4.6.6, tag 0x9403).
     * @param float|null             $accelerationMs2         Camera acceleration magnitude in m/s² (EXIF 3.0 §4.6.6, tag 0x9404).
     * @param float|null             $cameraElevationAngleDeg Camera elevation angle in degrees (EXIF 3.0 §4.6.6, tag 0x9405).
     */
    public function __construct(
        public ?DateTimeImmutable $dateTime,
        public ?float $temperatureC,
        public ?float $humidityPercent,
        public ?float $pressureHPa,
        public ?float $waterDepthM,
        public ?float $accelerationMs2,
        public ?float $cameraElevationAngleDeg,
    ) {
    }
}
