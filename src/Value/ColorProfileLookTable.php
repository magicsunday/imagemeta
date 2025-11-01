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
 * Describes a DNG profile look table with three-channel colour adjustments.
 */
final readonly class ColorProfileLookTable
{
    /**
     * @param int|null                                       $hueDivisions        Number of hue divisions encoded in the table.
     * @param int|null                                       $saturationDivisions Number of saturation divisions encoded in the table.
     * @param int|null                                       $valueDivisions      Number of value divisions encoded in the table.
     * @param list<array{0: float, 1: float, 2: float}>|null $entries             Per-entry RGB adjustments in floating point.
     */
    public function __construct(
        public readonly ?int $hueDivisions,
        public readonly ?int $saturationDivisions,
        public readonly ?int $valueDivisions,
        public readonly ?array $entries,
    ) {
    }
}
