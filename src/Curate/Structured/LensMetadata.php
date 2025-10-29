<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Lens as LensValue;

/**
 * Provides lens information including derived optical characteristics.
 */
final readonly class LensMetadata
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

    public ?float $cropFactor;

    public ?float $hyperfocalDistance;

    public ?float $fieldOfViewHorizontal;

    public ?float $fieldOfViewVertical;

    public ?float $fieldOfViewDiagonal;

    private ?int $equivalent35mm;

    public function __construct(
        LensValue $lens,
        Derived $derived,
    ) {
        $this->make                  = $lens->lensMake;
        $this->model                 = $lens->lensModel;
        $this->serialNumber          = $lens->lensSerialNumber;
        $this->focalLength           = $lens->focalLengthMm;
        $this->maximumAperture       = $lens->maxApertureFNumber;
        $this->specification         = $lens->lensSpecification;
        $this->equivalent35mm        = $lens->focalLengthIn35mm ?? $derived->focalLength35mm;
        $this->cropFactor            = $derived->cropFactor;
        $this->hyperfocalDistance    = $derived->hyperfocalM;
        $this->fieldOfViewHorizontal = $derived->fovHorizontalDeg;
        $this->fieldOfViewVertical   = $derived->fovVerticalDeg;
        $this->fieldOfViewDiagonal   = $derived->fovDiagonalDeg;
    }

    public function equivalent35mm(): ?int
    {
        return $this->equivalent35mm;
    }
}
