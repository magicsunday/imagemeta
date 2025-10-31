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
 *
 * @deprecated since milestone M4. This transitional wrapper will be removed in the
 *             following release. Consume the underlying Value objects directly instead.
 */
final readonly class Exposure
{
    public function __construct(
        private ExposureValue $exposure,
        private Derived $derived,
    ) {
    }

    public function value(): ExposureValue
    {
        return $this->exposure;
    }

    public function derived(): Derived
    {
        return $this->derived;
    }

    public function iso(): ?int
    {
        return $this->exposure->iso;
    }

    public function exposureTimeSec(): ?float
    {
        return $this->exposure->exposureTimeSec;
    }

    public function fNumber(): ?float
    {
        return $this->exposure->fNumber;
    }

    public function exposureBiasEv(): ?float
    {
        return $this->exposure->exposureBiasEv;
    }

    public function program(): ?ExposureProgram
    {
        return $this->exposure->program;
    }

    public function meteringMode(): ?MeteringMode
    {
        return $this->exposure->meteringMode;
    }

    public function flash(): ?FlashInfo
    {
        return $this->exposure->flash;
    }

    public function whiteBalance(): ?WhiteBalance
    {
        return $this->exposure->whiteBalance;
    }

    public function brightnessEv(): ?float
    {
        return $this->exposure->brightnessEv;
    }

    public function exposureMode(): ?ExposureMode
    {
        return $this->exposure->exposureMode;
    }

    public function gainControl(): ?GainControl
    {
        return $this->exposure->gainControl;
    }

    public function contrast(): ?Contrast
    {
        return $this->exposure->contrast;
    }

    public function saturation(): ?Saturation
    {
        return $this->exposure->saturation;
    }

    public function sharpness(): ?Sharpness
    {
        return $this->exposure->sharpness;
    }

    public function digitalZoomRatio(): ?float
    {
        return $this->exposure->digitalZoomRatio;
    }

    public function shutterSpeedEv(): ?float
    {
        return $this->exposure->shutterSpeedEv;
    }

    public function apertureEv(): ?float
    {
        return $this->exposure->apertureEv;
    }

    public function isoLatitudeYyy(): ?int
    {
        return $this->exposure->isoLatitudeYyy;
    }

    public function isoLatitudeZzz(): ?int
    {
        return $this->exposure->isoLatitudeZzz;
    }

    public function exposureIndex(): ?float
    {
        return $this->exposure->exposureIndex;
    }

    public function flashEnergy(): ?float
    {
        return $this->exposure->flashEnergy;
    }

    public function ev100(): ?float
    {
        return $this->derived->ev100;
    }
}
