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
 * Core exposure parameters including sensitivity, aperture, and shutter speed.
 */
final readonly class ExposureSettings
{
    /**
     * @param int|null   $iso             ISO sensitivity.
     * @param float|null $exposureIndex   Exposure index value.
     * @param int|null   $isoLatitudeYyy  ISO latitude yyy value.
     * @param int|null   $isoLatitudeZzz  ISO latitude zzz value.
     * @param float|null $exposureTimeSec Exposure time in seconds.
     * @param float|null $shutterSpeedEv  Shutter speed expressed as APEX value.
     * @param float|null $fNumber         Aperture (f-number).
     * @param float|null $apertureEv      Aperture expressed as APEX value.
     * @param float|null $exposureBiasEv  Exposure compensation in EV.
     * @param float|null $brightnessEv    Scene brightness value in EV.
     */
    public function __construct(
        public ?int $iso = null,
        public ?float $exposureIndex = null,
        public ?int $isoLatitudeYyy = null,
        public ?int $isoLatitudeZzz = null,
        public ?float $exposureTimeSec = null,
        public ?float $shutterSpeedEv = null,
        public ?float $fNumber = null,
        public ?float $apertureEv = null,
        public ?float $exposureBiasEv = null,
        public ?float $brightnessEv = null,
    ) {
    }
}
