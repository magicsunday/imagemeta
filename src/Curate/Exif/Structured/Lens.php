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
 */
final readonly class Lens
{
    public ?string $make;

    public ?string $model;

    public ?string $serialNumber;

    public ?float $focalLength;

    public ?float $maximumAperture;

    /**
     * @var array{0:float,1:float,2:float,3:float}|null
     */
    public ?array $specification;

    public ?int $equivalent35mm;

    public ?float $cropFactor;

    public ?float $hyperfocalDistance;

    public ?float $fieldOfViewHorizontal;

    public ?float $fieldOfViewVertical;

    public ?float $fieldOfViewDiagonal;

    /**
     * @param LensValue $lens    Raw lens value object with EXIF optical information such as make, model and focal lengths.
     * @param Derived   $derived Derived helper supplying computed crop factor, hyperfocal distance and FOV values.
     */
    public function __construct(LensValue $lens, Derived $derived)
    {
        $this->make            = $lens->lensMake;
        $this->model           = $lens->lensModel;
        $this->serialNumber    = $lens->lensSerialNumber;
        $this->focalLength     = $lens->focalLengthMm;
        $this->maximumAperture = $lens->maxApertureFNumber;
        $this->specification   = $lens->lensSpecification;
        // Fill missing EXIF equivalent focal length with the derived 35mm calculation for downstream consumers.
        $this->equivalent35mm        = $lens->focalLengthIn35mm ?? $derived->focalLength35mm;
        $this->cropFactor            = $derived->cropFactor;
        $this->hyperfocalDistance    = $derived->hyperfocalM;
        $this->fieldOfViewHorizontal = $derived->fovHorizontalDeg;
        $this->fieldOfViewVertical   = $derived->fovVerticalDeg;
        $this->fieldOfViewDiagonal   = $derived->fovDiagonalDeg;
    }

    public function make(): ?string
    {
        return $this->make;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function serialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function focalLength(): ?float
    {
        return $this->focalLength;
    }

    public function maximumAperture(): ?float
    {
        return $this->maximumAperture;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function specification(): ?array
    {
        return $this->specification;
    }

    public function equivalent35mm(): ?int
    {
        return $this->equivalent35mm;
    }

    public function cropFactor(): ?float
    {
        return $this->cropFactor;
    }

    public function hyperfocalDistance(): ?float
    {
        return $this->hyperfocalDistance;
    }

    public function fieldOfViewHorizontal(): ?float
    {
        return $this->fieldOfViewHorizontal;
    }

    public function fieldOfViewVertical(): ?float
    {
        return $this->fieldOfViewVertical;
    }

    public function fieldOfViewDiagonal(): ?float
    {
        return $this->fieldOfViewDiagonal;
    }
}
