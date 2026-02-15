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
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;

/**
 * Provides image-domain accessors on top of ParsedExif.
 */
final readonly class ImageMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function width(): ?int
    {
        return $this->parsedExif->imageWidth();
    }

    public function height(): ?int
    {
        return $this->parsedExif->imageHeight();
    }

    public function orientation(): Orientation
    {
        return $this->parsedExif->orientation();
    }

    public function compression(): ?Compression
    {
        return $this->parsedExif->compression();
    }

    public function colorSpace(): ?ColorSpace
    {
        return $this->parsedExif->colorSpace();
    }
}
