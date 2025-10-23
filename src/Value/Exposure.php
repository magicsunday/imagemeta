<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;

/**
 * Aggregates exposure related measurements.
 */
final readonly class Exposure
{
    /**
     * @param int|null           $iso                 ISO sensitivity.
     * @param float|null         $exposureTimeSeconds Exposure time in seconds.
     * @param float|null         $apertureFNumber     Aperture (f-number).
     * @param float|null         $focalLengthMm       Focal length used in millimetres.
     * @param ExposureProgram|null $program           Selected exposure program.
     * @param MeteringMode|null    $meteringMode      Metering mode.
     * @param WhiteBalance|null    $whiteBalance      White balance setting.
     * @param FlashInfo|null       $flash             Flash details.
     */
    public function __construct(
        public ?int $iso,
        public ?float $exposureTimeSeconds,
        public ?float $apertureFNumber,
        public ?float $focalLengthMm,
        public ?ExposureProgram $program = null,
        public ?MeteringMode $meteringMode = null,
        public ?WhiteBalance $whiteBalance = null,
        public ?FlashInfo $flash = null,
    ) {
    }
}
