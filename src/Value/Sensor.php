<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Provides sensor related characteristics.
 */
final readonly class Sensor
{
    /**
     * @param float|null  $pixelPitchUm Pixel pitch in micrometres.
     * @param int|null    $cfaWidth     Width of the repeating CFA pattern.
     * @param int|null    $cfaHeight    Height of the repeating CFA pattern.
     * @param string|null $sensorType   Sensor technology (e.g. CCD or CMOS).
     * @param bool|null   $ibis         Indicates in-body image stabilisation support.
     */
    public function __construct(
        public ?float $pixelPitchUm,
        public ?int $cfaWidth,
        public ?int $cfaHeight,
        public ?string $sensorType,
        public ?bool $ibis,
    ) {
    }
}
