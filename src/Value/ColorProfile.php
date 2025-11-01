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
 * Provides ICC colour profile information.
 */
final readonly class ColorProfile
{
    /**
     * @param string|null                $profileName                 Human readable profile description.
     * @param string|null                $profileVersion              Profile version string.
     * @param string|null                $pcs                         Profile connection space.
     * @param string|null                $renderingIntent             Rendering intent description.
     * @param float|null                 $gamma                       Scene gamma value when provided by EXIF.
     * @param string|null                $profileId                   Optional profile identifier (MD5) when available.
     * @param string|null                $cameraCalibrationSignature  Optional DNG camera calibration signature.
     * @param string|null                $profileCalibrationSignature Optional DNG profile calibration signature.
     * @param ColorProfileHueSatMap|null $hueSatMap                   Optional hue/saturation/value correction map.
     * @param ColorProfileLookTable|null $lookTable                   Optional profile look table data.
     * @param ColorProfileToneCurve|null $toneCurve                   Optional profile tone curve definition.
     * @param ColorProfileGainMap|null   $gainMap                     Optional profile gain map payload.
     */
    public function __construct(
        public readonly ?string $profileName,
        public readonly ?string $profileVersion,
        public readonly ?string $pcs,
        public readonly ?string $renderingIntent,
        public readonly ?float $gamma,
        public readonly ?string $profileId = null,
        public readonly ?string $cameraCalibrationSignature = null,
        public readonly ?string $profileCalibrationSignature = null,
        public readonly ?ColorProfileHueSatMap $hueSatMap = null,
        public readonly ?ColorProfileLookTable $lookTable = null,
        public readonly ?ColorProfileToneCurve $toneCurve = null,
        public readonly ?ColorProfileGainMap $gainMap = null,
    ) {
    }
}
