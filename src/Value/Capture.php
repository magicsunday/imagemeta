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
 * Holds capture specific timestamps.
 */
final readonly class Capture
{
    /**
     * @param DateTimeImmutable|null $dateTime                Capture timestamp.
     * @param float|null             $temperatureC            Recorded temperature in Celsius.
     * @param float|null             $humidityPercent         Relative humidity percentage.
     * @param float|null             $pressureHPa             Ambient pressure in hPa.
     * @param float|null             $batteryLevelPercent     Battery level percentage.
     * @param float|null             $waterDepthM             Water depth in metres.
     * @param float|null             $accelerationMs2         Camera acceleration in metres per second squared.
     * @param float|null             $cameraElevationAngleDeg Camera elevation angle in degrees.
     * @param int|null               $selfTimerModeSeconds    Configured self-timer delay in seconds.
     */
    public function __construct(
        public ?DateTimeImmutable $dateTime,
        public ?float $temperatureC,
        public ?float $humidityPercent,
        public ?float $pressureHPa,
        public ?float $batteryLevelPercent,
        public ?float $waterDepthM,
        public ?float $accelerationMs2,
        public ?float $cameraElevationAngleDeg,
        public ?int $selfTimerModeSeconds,
    ) {
    }
}
