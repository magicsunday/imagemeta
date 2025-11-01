<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;

/**
 * Aggregates exposure related measurements.
 */
final readonly class Exposure
{
    /**
     * @param int|null             $iso              ISO sensitivity.
     * @param float|null           $exposureTimeSec  Exposure time in seconds.
     * @param float|null           $fNumber          Aperture (f-number).
     * @param float|null           $exposureBiasEv   Exposure compensation in EV.
     * @param ExposureProgram|null $program          Selected exposure program.
     * @param MeteringMode|null    $meteringMode     Metering mode.
     * @param FlashInfo|null       $flash            Flash details.
     * @param WhiteBalance|null    $whiteBalance     White balance setting.
     * @param float|null           $brightnessEv     Scene brightness value in EV.
     * @param ExposureMode|null    $exposureMode     Exposure mode selection.
     * @param GainControl|null     $gainControl      Applied gain control.
     * @param Contrast|null        $contrast         Contrast processing setting.
     * @param Saturation|null      $saturation       Saturation processing setting.
     * @param Sharpness|null       $sharpness        Sharpness processing setting.
     * @param float|null           $digitalZoomRatio Applied digital zoom ratio.
     * @param float|null           $shutterSpeedEv   Shutter speed expressed as APEX value.
     * @param float|null           $apertureEv       Aperture expressed as APEX value.
     * @param int|null             $isoLatitudeYyy   ISO latitude yyy value.
     * @param int|null             $isoLatitudeZzz   ISO latitude zzz value.
     * @param float|null           $exposureIndex    Exposure index value.
     * @param float|null           $flashEnergy      Flash energy measured in beam candle power seconds.
     */
    public function __construct(
        public readonly ?int $iso,
        public readonly ?float $exposureTimeSec,
        public readonly ?float $fNumber,
        public readonly ?float $exposureBiasEv,
        public readonly ?ExposureProgram $program,
        public readonly ?MeteringMode $meteringMode,
        public readonly ?FlashInfo $flash,
        public readonly ?WhiteBalance $whiteBalance,
        public readonly ?float $brightnessEv,
        public readonly ?ExposureMode $exposureMode,
        public readonly ?GainControl $gainControl,
        public readonly ?Contrast $contrast,
        public readonly ?Saturation $saturation,
        public readonly ?Sharpness $sharpness,
        public readonly ?float $digitalZoomRatio,
        public readonly ?float $shutterSpeedEv,
        public readonly ?float $apertureEv,
        public readonly ?int $isoLatitudeYyy,
        public readonly ?int $isoLatitudeZzz,
        public readonly ?float $exposureIndex,
        public readonly ?float $flashEnergy,
    ) {
    }
}
