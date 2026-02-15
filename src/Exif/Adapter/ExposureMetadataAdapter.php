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
 * Provides exposure-domain accessors on top of ParsedExif.
 */
final readonly class ExposureMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function iso(): ?int
    {
        return $this->parsedExif->iso();
    }

    public function exposureTime(): ?float
    {
        return $this->parsedExif->exposureTime();
    }

    public function aperture(): ?float
    {
        return $this->parsedExif->fNumber();
    }

    public function shutterSpeedSeconds(): ?float
    {
        return $this->parsedExif->shutterSpeedSeconds();
    }

    public function exposureBias(): ?float
    {
        return $this->parsedExif->exposureBias();
    }
}
