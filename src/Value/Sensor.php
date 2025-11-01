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
     * @param float|null                                                                                                                                                   $pixelPitchUm             Pixel pitch in micrometres.
     * @param int|null                                                                                                                                                     $cfaWidth                 Width of the repeating CFA pattern.
     * @param int|null                                                                                                                                                     $cfaHeight                Height of the repeating CFA pattern.
     * @param string|null                                                                                                                                                  $sensorType               Sensor technology (e.g. CCD or CMOS).
     * @param bool|null                                                                                                                                                    $ibis                     Indicates in-body image stabilisation support.
     * @param list<int>|null                                                                                                                                               $cfaPattern               Colour filter array pattern definition.
     * @param string|null                                                                                                                                                  $spectralSensitivity      Spectral sensitivity description.
     * @param array{payload:string, matrix:(array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null)}|null $oecf                     Opto-electronic conversion function payload and decoded matrix.
     * @param array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null                                      $spatialFrequencyResponse Spatial frequency response table decoded from the EXIF payload.
     * @param float|null                                                                                                                                                   $focalPlaneXResolution    Focal plane X resolution.
     * @param float|null                                                                                                                                                   $focalPlaneYResolution    Focal plane Y resolution.
     * @param ResolutionUnit|null                                                                                                                                          $focalPlaneResolutionUnit Focal plane resolution unit.
     */
    public function __construct(
        public readonly ?float $pixelPitchUm,
        public readonly ?int $cfaWidth,
        public readonly ?int $cfaHeight,
        public readonly ?string $sensorType,
        public readonly ?bool $ibis,
        public readonly ?array $cfaPattern,
        public readonly ?string $spectralSensitivity,
        public readonly ?array $oecf,
        public readonly ?array $spatialFrequencyResponse,
        public readonly ?float $focalPlaneXResolution,
        public readonly ?float $focalPlaneYResolution,
        public readonly ?ResolutionUnit $focalPlaneResolutionUnit,
    ) {
    }
}
