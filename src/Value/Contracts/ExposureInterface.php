<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\FlashInfo;

interface ExposureInterface
{
    public function iso(): ?int;

    public function exposureTimeSec(): ?float;

    public function fNumber(): ?float;

    public function exposureBiasEv(): ?float;

    public function program(): ?ExposureProgram;

    public function meteringMode(): ?MeteringMode;

    public function flash(): ?FlashInfo;

    public function whiteBalance(): ?WhiteBalance;

    public function brightnessEv(): ?float;

    public function exposureMode(): ?ExposureMode;

    public function gainControl(): ?GainControl;

    public function contrast(): ?Contrast;

    public function saturation(): ?Saturation;

    public function sharpness(): ?Sharpness;

    public function digitalZoomRatio(): ?float;

    public function shutterSpeedEv(): ?float;

    public function apertureEv(): ?float;

    public function isoLatitudeYyy(): ?int;

    public function isoLatitudeZzz(): ?int;

    public function exposureIndex(): ?float;

    public function flashEnergy(): ?float;
}
