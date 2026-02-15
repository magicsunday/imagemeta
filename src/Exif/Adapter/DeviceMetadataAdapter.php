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
 * Provides device-domain accessors on top of ParsedExif.
 */
final readonly class DeviceMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function software(): ?string
    {
        return $this->parsedExif->software();
    }

    public function rawDevelopingSoftware(): ?string
    {
        return $this->parsedExif->rawDevelopingSoftware();
    }

    public function imageEditingSoftware(): ?string
    {
        return $this->parsedExif->imageEditingSoftware();
    }

    public function metadataEditingSoftware(): ?string
    {
        return $this->parsedExif->metadataEditingSoftware();
    }
}
