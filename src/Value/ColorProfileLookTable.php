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
        public ?int $hueDivisions,
        public ?int $saturationDivisions,
        public ?int $valueDivisions,
        public ?array $entries,
    ) {
    }

    /**
     * Returns the number of hue divisions encoded in the table.
     */
    public function hueDivisions(): ?int
    {
        return $this->hueDivisions;
    }

    /**
     * Returns the number of saturation divisions encoded in the table.
     */
    public function saturationDivisions(): ?int
    {
        return $this->saturationDivisions;
    }

    /**
     * Returns the number of value divisions encoded in the table.
     */
    public function valueDivisions(): ?int
    {
        return $this->valueDivisions;
    }

    /**
     * Returns the per-entry RGB adjustments in floating point.
     *
     * @return list<array{0: float, 1: float, 2: float}>|null
     */
    public function entries(): ?array
    {
        return $this->entries;
    }
}
