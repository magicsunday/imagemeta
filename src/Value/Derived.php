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
 * Holds derived secondary metrics calculated from the base metadata.
 */
final readonly class Derived
{
    /**
     * @param float|null $ev100            Exposure value normalised to ISO 100.
     * @param float|null $hyperfocalM      Hyperfocal distance in metres.
     * @param float|null $fovDiagonalDeg   Diagonal field of view in degrees.
     * @param float|null $fovHorizontalDeg Horizontal field of view in degrees.
     * @param float|null $fovVerticalDeg   Vertical field of view in degrees.
     * @param int|null   $focalLength35mm  Equivalent focal length in 35mm terms.
     * @param float|null $cropFactor       Estimated crop factor.
     */
    public function __construct(
        public ?float $ev100,
        public ?float $hyperfocalM,
        public ?float $fovDiagonalDeg,
        public ?float $fovHorizontalDeg,
        public ?float $fovVerticalDeg,
        public ?int $focalLength35mm,
        public ?float $cropFactor,
    ) {
    }

    /**
     * Returns the exposure value normalised to ISO 100.
     */
    public function ev100(): ?float
    {
        return $this->ev100;
    }

    /**
     * Returns the hyperfocal distance in metres.
     */
    public function hyperfocalM(): ?float
    {
        return $this->hyperfocalM;
    }

    /**
     * Returns the diagonal field of view in degrees.
     */
    public function fovDiagonalDeg(): ?float
    {
        return $this->fovDiagonalDeg;
    }

    /**
     * Returns the horizontal field of view in degrees.
     */
    public function fovHorizontalDeg(): ?float
    {
        return $this->fovHorizontalDeg;
    }

    /**
     * Returns the vertical field of view in degrees.
     */
    public function fovVerticalDeg(): ?float
    {
        return $this->fovVerticalDeg;
    }

    /**
     * Returns the 35mm equivalent focal length.
     */
    public function focalLength35mm(): ?int
    {
        return $this->focalLength35mm;
    }

    /**
     * Returns the estimated crop factor.
     */
    public function cropFactor(): ?float
    {
        return $this->cropFactor;
    }
}
