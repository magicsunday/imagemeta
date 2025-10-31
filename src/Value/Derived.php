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
     * @param float|null $ev100                      Exposure value normalised to ISO 100.
     * @param float|null $hyperfocalDistanceMetres   Hyperfocal distance in metres.
     * @param float|null $fieldOfViewDiagonalDeg     Diagonal field of view in degrees.
     * @param float|null $fieldOfViewHorizontalDeg   Horizontal field of view in degrees.
     * @param float|null $fieldOfViewVerticalDeg     Vertical field of view in degrees.
     * @param int|null   $equivalent35mm             Equivalent focal length in 35mm terms.
     * @param float|null $cropFactor                 Estimated crop factor.
     */
    public function __construct(
        public ?float $ev100,
        public ?float $hyperfocalDistanceMetres,
        public ?float $fieldOfViewDiagonalDeg,
        public ?float $fieldOfViewHorizontalDeg,
        public ?float $fieldOfViewVerticalDeg,
        public ?int $equivalent35mm,
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
    public function hyperfocalDistanceMetres(): ?float
    {
        return $this->hyperfocalDistanceMetres;
    }

    /**
     * Returns the hyperfocal distance in metres.
     *
     * @deprecated Use {@see hyperfocalDistanceMetres()} instead.
     */
    public function hyperfocalM(): ?float
    {
        return $this->hyperfocalDistanceMetres();
    }

    /**
     * Returns the diagonal field of view in degrees.
     */
    public function fieldOfViewDiagonalDeg(): ?float
    {
        return $this->fieldOfViewDiagonalDeg;
    }

    /**
     * Returns the diagonal field of view in degrees.
     *
     * @deprecated Use {@see fieldOfViewDiagonalDeg()} instead.
     */
    public function fovDiagonalDeg(): ?float
    {
        return $this->fieldOfViewDiagonalDeg();
    }

    /**
     * Returns the horizontal field of view in degrees.
     */
    public function fieldOfViewHorizontalDeg(): ?float
    {
        return $this->fieldOfViewHorizontalDeg;
    }

    /**
     * Returns the horizontal field of view in degrees.
     *
     * @deprecated Use {@see fieldOfViewHorizontalDeg()} instead.
     */
    public function fovHorizontalDeg(): ?float
    {
        return $this->fieldOfViewHorizontalDeg();
    }

    /**
     * Returns the vertical field of view in degrees.
     */
    public function fieldOfViewVerticalDeg(): ?float
    {
        return $this->fieldOfViewVerticalDeg;
    }

    /**
     * Returns the vertical field of view in degrees.
     *
     * @deprecated Use {@see fieldOfViewVerticalDeg()} instead.
     */
    public function fovVerticalDeg(): ?float
    {
        return $this->fieldOfViewVerticalDeg();
    }

    /**
     * Returns the 35mm equivalent focal length.
     */
    public function equivalent35mm(): ?int
    {
        return $this->equivalent35mm;
    }

    /**
     * Returns the 35mm equivalent focal length.
     *
     * @deprecated Use {@see equivalent35mm()} instead.
     */
    public function focalLength35mm(): ?int
    {
        return $this->equivalent35mm();
    }

    /**
     * Returns the estimated crop factor.
     */
    public function cropFactor(): ?float
    {
        return $this->cropFactor;
    }
}
