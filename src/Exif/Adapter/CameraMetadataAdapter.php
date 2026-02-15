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
 * Provides camera-domain accessors on top of ParsedExif.
 */
final readonly class CameraMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    public function make(): ?string
    {
        return $this->parsedExif->cameraMake();
    }

    public function model(): ?string
    {
        return $this->parsedExif->cameraModel();
    }

    public function bodySerialNumber(): ?string
    {
        return $this->parsedExif->bodySerialNumber();
    }

    public function firmware(): ?string
    {
        return $this->parsedExif->cameraFirmware();
    }
}
