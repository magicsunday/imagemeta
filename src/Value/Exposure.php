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
     * Creates an exposure settings metadata value object.
     *
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
        public ?int $iso,
        public ?float $exposureTimeSec,
        public ?float $fNumber,
        public ?float $exposureBiasEv,
        public ?ExposureProgram $program,
        public ?MeteringMode $meteringMode,
        public ?FlashInfo $flash,
        public ?WhiteBalance $whiteBalance,
        public ?float $brightnessEv,
        public ?ExposureMode $exposureMode,
        public ?GainControl $gainControl,
        public ?Contrast $contrast,
        public ?Saturation $saturation,
        public ?Sharpness $sharpness,
        public ?float $digitalZoomRatio,
        public ?float $shutterSpeedEv,
        public ?float $apertureEv,
        public ?int $isoLatitudeYyy,
        public ?int $isoLatitudeZzz,
        public ?float $exposureIndex,
        public ?float $flashEnergy,
    ) {
    }
}
