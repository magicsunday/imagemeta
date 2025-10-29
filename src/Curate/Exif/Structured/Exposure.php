<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Exposure as ExposureValue;
use MagicSunday\ImageMeta\Value\FlashInfo;

/**
 * Captures EXIF exposure values alongside derived exposure metrics.
 */
final readonly class Exposure
{
    public ?int $iso;

    public ?float $exposureTimeSec;

    public ?float $fNumber;

    public ?float $exposureBiasEv;

    public ?ExposureProgram $program;

    public ?MeteringMode $meteringMode;

    public ?FlashInfo $flash;

    public ?WhiteBalance $whiteBalance;

    public ?float $brightnessEv;

    public ?ExposureMode $exposureMode;

    public ?GainControl $gainControl;

    public ?Contrast $contrast;

    public ?Saturation $saturation;

    public ?Sharpness $sharpness;

    public ?float $digitalZoomRatio;

    public ?float $shutterSpeedEv;

    public ?float $apertureEv;

    public ?int $isoLatitudeYyy;

    public ?int $isoLatitudeZzz;

    public ?float $exposureIndex;

    public ?float $flashEnergy;

    public ?float $ev100;

    /**
     * @param ExposureValue $exposure Raw exposure value object with EXIF shutter, aperture and metering metadata.
     * @param Derived       $derived  Derived optics helper providing calculated EV100 and related exposure math.
     */
    public function __construct(ExposureValue $exposure, Derived $derived)
    {
        $this->iso              = $exposure->iso;
        $this->exposureTimeSec  = $exposure->exposureTimeSec;
        $this->fNumber          = $exposure->fNumber;
        $this->exposureBiasEv   = $exposure->exposureBiasEv;
        $this->program          = $exposure->program;
        $this->meteringMode     = $exposure->meteringMode;
        $this->flash            = $exposure->flash;
        $this->whiteBalance     = $exposure->whiteBalance;
        $this->brightnessEv     = $exposure->brightnessEv;
        $this->exposureMode     = $exposure->exposureMode;
        $this->gainControl      = $exposure->gainControl;
        $this->contrast         = $exposure->contrast;
        $this->saturation       = $exposure->saturation;
        $this->sharpness        = $exposure->sharpness;
        $this->digitalZoomRatio = $exposure->digitalZoomRatio;
        $this->shutterSpeedEv   = $exposure->shutterSpeedEv;
        $this->apertureEv       = $exposure->apertureEv;
        $this->isoLatitudeYyy   = $exposure->isoLatitudeYyy;
        $this->isoLatitudeZzz   = $exposure->isoLatitudeZzz;
        $this->exposureIndex    = $exposure->exposureIndex;
        $this->flashEnergy      = $exposure->flashEnergy;
        // EV100 stems from the derived helper and expresses exposure at ISO 100 regardless of the recorded ISO.
        $this->ev100 = $derived->ev100;
    }

    public function iso(): ?int
    {
        return $this->iso;
    }

    public function exposureTimeSec(): ?float
    {
        return $this->exposureTimeSec;
    }

    public function fNumber(): ?float
    {
        return $this->fNumber;
    }

    public function exposureBiasEv(): ?float
    {
        return $this->exposureBiasEv;
    }

    public function program(): ?ExposureProgram
    {
        return $this->program;
    }

    public function meteringMode(): ?MeteringMode
    {
        return $this->meteringMode;
    }

    public function flash(): ?FlashInfo
    {
        return $this->flash;
    }

    public function whiteBalance(): ?WhiteBalance
    {
        return $this->whiteBalance;
    }

    public function brightnessEv(): ?float
    {
        return $this->brightnessEv;
    }

    public function exposureMode(): ?ExposureMode
    {
        return $this->exposureMode;
    }

    public function gainControl(): ?GainControl
    {
        return $this->gainControl;
    }

    public function contrast(): ?Contrast
    {
        return $this->contrast;
    }

    public function saturation(): ?Saturation
    {
        return $this->saturation;
    }

    public function sharpness(): ?Sharpness
    {
        return $this->sharpness;
    }

    public function digitalZoomRatio(): ?float
    {
        return $this->digitalZoomRatio;
    }

    public function shutterSpeedEv(): ?float
    {
        return $this->shutterSpeedEv;
    }

    public function apertureEv(): ?float
    {
        return $this->apertureEv;
    }

    public function isoLatitudeYyy(): ?int
    {
        return $this->isoLatitudeYyy;
    }

    public function isoLatitudeZzz(): ?int
    {
        return $this->isoLatitudeZzz;
    }

    public function exposureIndex(): ?float
    {
        return $this->exposureIndex;
    }

    public function flashEnergy(): ?float
    {
        return $this->flashEnergy;
    }

    public function ev100(): ?float
    {
        return $this->ev100;
    }
}
