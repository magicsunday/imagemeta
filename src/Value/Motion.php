<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Captures motion sensor data recorded during capture (e.g. Apple live photos).
 */
final readonly class Motion
{
    /**
     * @param float|null $rollDeg  Roll angle in degrees.
     * @param float|null $pitchDeg Pitch angle in degrees.
     * @param float|null $yawDeg   Yaw angle in degrees.
     * @param float|null $accelX   Acceleration along the X axis.
     * @param float|null $accelY   Acceleration along the Y axis.
     * @param float|null $accelZ   Acceleration along the Z axis.
     * @param float|null $gyroX    Gyroscope reading around the X axis.
     * @param float|null $gyroY    Gyroscope reading around the Y axis.
     * @param float|null $gyroZ    Gyroscope reading around the Z axis.
     */
    public function __construct(
        public readonly ?float $rollDeg,
        public readonly ?float $pitchDeg,
        public readonly ?float $yawDeg,
        public readonly ?float $accelX,
        public readonly ?float $accelY,
        public readonly ?float $accelZ,
        public readonly ?float $gyroX,
        public readonly ?float $gyroY,
        public readonly ?float $gyroZ,
    ) {
    }
}
