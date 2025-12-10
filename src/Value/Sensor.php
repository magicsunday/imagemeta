<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;

/**
 * Provides sensor related characteristics.
 */
final readonly class Sensor
{
    /**
     * Creates a sensor characteristics metadata value object.
     *
     * @param float|null                    $pixelPitchUm             Pixel pitch in micrometres.
     * @param string|null                   $sensorType               Sensor technology (e.g. CCD or CMOS).
     * @param bool                          $ibis                     Indicates in-body image stabilisation support.
     * @param list<int>|null                $cfaPattern               Colour filter array pattern definition.
     * @param string|null                   $spectralSensitivity      Spectral sensitivity description.
     * @param Oecf|null                     $oecf                     Opto-electronic conversion function (EXIF 3.0 §4.6.3).
     * @param SpatialFrequencyResponse|null $spatialFrequencyResponse Spatial frequency response (EXIF 3.0 §4.6.3).
     * @param float|null                    $focalPlaneXResolution    Focal plane X resolution (EXIF 3.0 §4.6.6.7.26).
     * @param float|null                    $focalPlaneYResolution    Focal plane Y resolution (EXIF 3.0 §4.6.6.7.27).
     * @param ResolutionUnit|null           $focalPlaneResolutionUnit Focal plane resolution unit (EXIF 3.0 §4.6.6.7.28).
     */
    public function __construct(
        public ?float $pixelPitchUm = null,
        public ?string $sensorType = null,
        public bool $ibis = false,
        public ?array $cfaPattern = null,
        public ?string $spectralSensitivity = null,
        public ?Oecf $oecf = null,
        public ?SpatialFrequencyResponse $spatialFrequencyResponse = null,
        public ?float $focalPlaneXResolution = null,
        public ?float $focalPlaneYResolution = null,
        public ?ResolutionUnit $focalPlaneResolutionUnit = null,
    ) {
    }
}
