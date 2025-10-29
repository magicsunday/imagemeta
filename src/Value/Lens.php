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
 * Represents lens information used when capturing the image.
 */
final readonly class Lens
{
    /**
     * @var array{0:float,1:float,2:float,3:float}|null
     */
    public ?array $lensSpecification;

    /**
     * @param string|null                                 $lensMake           Lens manufacturer.
     * @param string|null                                 $lensModel          Lens model description.
     * @param string|null                                 $lensSerialNumber   Serial number reported by the lens.
     * @param float|null                                  $focalLengthMm      Focal length used in millimetres.
     * @param int|null                                    $focalLengthIn35mm  35mm equivalent focal length.
     * @param float|null                                  $maxApertureFNumber Maximum aperture value as f-number.
     * @param array{0:float,1:float,2:float,3:float}|null $lensSpecification  Lens specification describing zoom and aperture range.
     */
    public function __construct(
        public ?string $lensMake,
        public ?string $lensModel,
        public ?string $lensSerialNumber,
        public ?float $focalLengthMm,
        public ?int $focalLengthIn35mm,
        public ?float $maxApertureFNumber,
        ?array $lensSpecification = null,
    ) {
        $this->lensSpecification = $lensSpecification;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        return $this->lensSpecification;
    }

    public function lensMake(): ?string
    {
        return $this->lensMake;
    }

    public function lensModel(): ?string
    {
        return $this->lensModel;
    }

    public function lensSerialNumber(): ?string
    {
        return $this->lensSerialNumber;
    }

    public function focalLengthMm(): ?float
    {
        return $this->focalLengthMm;
    }

    public function focalLengthIn35mm(): ?int
    {
        return $this->focalLengthIn35mm;
    }

    public function maxApertureFNumber(): ?float
    {
        return $this->maxApertureFNumber;
    }
}
