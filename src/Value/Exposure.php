<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;

/**
 * Aggregates exposure related measurements.
 */
final readonly class Exposure
{
    /**
     * Creates an exposure metadata value object composed of domain sub-objects.
     *
     * @param ExposureSettings|null    $settings     Core exposure parameters including sensitivity and aperture.
     * @param ExposureAdjustments|null $adjustments  In-camera processing adjustments.
     * @param ExposureProgram|null     $program      Selected exposure program.
     * @param ExposureMode|null        $exposureMode Exposure mode selection.
     * @param MeteringMode|null        $meteringMode Metering mode.
     * @param FlashInfo|null           $flash        Flash details.
     * @param float|null               $flashEnergy  Flash energy measured in beam candle power seconds.
     */
    public function __construct(
        public ?ExposureSettings $settings = null,
        public ?ExposureAdjustments $adjustments = null,
        public ?ExposureProgram $program = null,
        public ?ExposureMode $exposureMode = null,
        public ?MeteringMode $meteringMode = null,
        public ?FlashInfo $flash = null,
        public ?float $flashEnergy = null,
    ) {
    }
}
