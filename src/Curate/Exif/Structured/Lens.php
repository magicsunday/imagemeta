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
use MagicSunday\ImageMeta\Value\Lens as LensValue;

/**
 * Represents EXIF lens metadata enriched with derived optical metrics.
 *
 * @deprecated since milestone M4. This transitional wrapper will be removed in the
 *             following release. Consume the underlying Value objects directly instead.
 */
final readonly class Lens
{
    public function __construct(
        private LensValue $lens,
        private Derived $derived,
    ) {
    }

    public function value(): LensValue
    {
        return $this->lens;
    }

    public function derived(): Derived
    {
        return $this->derived;
    }

    public function make(): ?string
    {
        return $this->lens->lensMake;
    }

    public function model(): ?string
    {
        return $this->lens->lensModel;
    }

    public function serialNumber(): ?string
    {
        return $this->lens->lensSerialNumber;
    }

    public function focalLength(): ?float
    {
        return $this->lens->focalLengthMm;
    }

    public function maximumAperture(): ?float
    {
        return $this->lens->maxApertureFNumber;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function specification(): ?array
    {
        return $this->lens->lensSpecification;
    }

    public function equivalent35mm(): ?int
    {
        return $this->lens->focalLengthIn35mm ?? $this->derived->focalLength35mm;
    }

    public function cropFactor(): ?float
    {
        return $this->derived->cropFactor;
    }

    public function hyperfocalDistance(): ?float
    {
        return $this->derived->hyperfocalM;
    }

    public function fieldOfViewHorizontal(): ?float
    {
        return $this->derived->fovHorizontalDeg;
    }

    public function fieldOfViewVertical(): ?float
    {
        return $this->derived->fovVerticalDeg;
    }

    public function fieldOfViewDiagonal(): ?float
    {
        return $this->derived->fovDiagonalDeg;
    }
}
