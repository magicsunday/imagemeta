<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Dji;

/**
 * Represents curated maker note data extracted from DJI drone images.
 *
 * Fields are based on documented DJI maker note tags from ExifTool.
 * The maker note is stored as a bare TIFF IFD using the parent EXIF byte order
 * and absolute TIFF offsets.
 */
final readonly class DjiMakerNotes
{
    /**
     * @param string|null $makerNoteVersion Maker note version string.
     * @param float|null  $speedX           Aircraft speed along the X axis (m/s).
     * @param float|null  $speedY           Aircraft speed along the Y axis (m/s).
     * @param float|null  $speedZ           Aircraft speed along the Z axis (m/s).
     * @param float|null  $pitch            Aircraft pitch angle (degrees).
     * @param float|null  $yaw              Aircraft yaw angle (degrees).
     * @param float|null  $roll             Aircraft roll angle (degrees).
     * @param float|null  $cameraPitch      Gimbal camera pitch angle (degrees).
     * @param float|null  $cameraYaw        Gimbal camera yaw angle (degrees).
     * @param float|null  $cameraRoll       Gimbal camera roll angle (degrees).
     * @param float|null  $compass          Compass heading (degrees).
     */
    public function __construct(
        public ?string $makerNoteVersion = null,
        public ?float $speedX = null,
        public ?float $speedY = null,
        public ?float $speedZ = null,
        public ?float $pitch = null,
        public ?float $yaw = null,
        public ?float $roll = null,
        public ?float $cameraPitch = null,
        public ?float $cameraYaw = null,
        public ?float $cameraRoll = null,
        public ?float $compass = null,
    ) {
    }
}
