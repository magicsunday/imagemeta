<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Adapter;

use MagicSunday\ImageMeta\Exif\Model\ParsedExif;

/**
 * Provides lens-domain accessors on top of ParsedExif.
 */
final readonly class LensMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function make(): ?string
    {
        return $this->parsedExif->lensMake();
    }

    public function model(): ?string
    {
        return $this->parsedExif->lensModel();
    }

    public function serialNumber(): ?string
    {
        return $this->parsedExif->lensSerialNumber();
    }

    public function focalLengthMm(): ?float
    {
        return $this->parsedExif->focalLengthMm();
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function specification(): ?array
    {
        return $this->parsedExif->lensSpecification();
    }
}
