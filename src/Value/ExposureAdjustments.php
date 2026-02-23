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
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;

/**
 * In-camera processing adjustments applied during capture.
 */
final readonly class ExposureAdjustments
{
    /**
     * @param WhiteBalance|null $whiteBalance     White balance setting.
     * @param Contrast|null     $contrast         Contrast processing setting.
     * @param Saturation|null   $saturation       Saturation processing setting.
     * @param Sharpness|null    $sharpness        Sharpness processing setting.
     * @param float|null        $digitalZoomRatio Applied digital zoom ratio.
     * @param GainControl|null  $gainControl      Applied gain control.
     */
    public function __construct(
        public ?WhiteBalance $whiteBalance = null,
        public ?Contrast $contrast = null,
        public ?Saturation $saturation = null,
        public ?Sharpness $sharpness = null,
        public ?float $digitalZoomRatio = null,
        public ?GainControl $gainControl = null,
    ) {
    }
}
