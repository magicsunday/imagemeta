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
     * @param int|null               $selfTimerModeSeconds    Configured self timer delay in seconds.
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

    /**
     * Returns the capture timestamp when available.
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->dateTime;
    }

    /**
     * Returns the recorded ambient temperature in Celsius.
     */
    public function temperatureC(): ?float
    {
        return $this->temperatureC;
    }

    /**
     * Returns the relative humidity percentage.
     */
    public function humidityPercent(): ?float
    {
        return $this->humidityPercent;
    }

    /**
     * Returns the ambient pressure in hectopascals.
     */
    public function pressureHPa(): ?float
    {
        return $this->pressureHPa;
    }

    /**
     * Returns the remaining battery level percentage.
     */
    public function batteryLevelPercent(): ?float
    {
        return $this->batteryLevelPercent;
    }

    /**
     * Returns the water depth in metres when the capture device provides it.
     */
    public function waterDepthM(): ?float
    {
        return $this->waterDepthM;
    }

    /**
     * Returns the camera acceleration in metres per second squared.
     */
    public function accelerationMs2(): ?float
    {
        return $this->accelerationMs2;
    }

    /**
     * Returns the camera elevation angle in degrees.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->cameraElevationAngleDeg;
    }

    /**
     * Returns the configured self-timer delay in seconds.
     */
    public function selfTimerModeSeconds(): ?int
    {
        return $this->selfTimerModeSeconds;
    }
}
