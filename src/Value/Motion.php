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
 *
 * EXIF 3.0 §4.6.6.8.6 Acceleration (0x9404): Records the 3D acceleration vector
 * as an SRATIONAL triplet in mGal (10^-5 m/s²). Individual components represent
 * acceleration along the X, Y, and Z axes of the camera's coordinate system.
 */
final readonly class Motion
{
    /**
     * Creates a motion sensor data value object.
     *
     * @param float|null $accelX Acceleration along the X axis in mGal.
     * @param float|null $accelY Acceleration along the Y axis in mGal.
     * @param float|null $accelZ Acceleration along the Z axis in mGal.
     */
    public function __construct(
        public ?float $accelX,
        public ?float $accelY,
        public ?float $accelZ,
    ) {
    }
}
