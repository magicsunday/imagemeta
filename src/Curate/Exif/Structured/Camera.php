<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use MagicSunday\ImageMeta\Value\Camera as CameraValue;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

/**
 * Provides EXIF backed camera metadata without container fallbacks.
 *
 * @deprecated since milestone M4. This transitional wrapper will be removed in the
 *             following release. Consume the underlying Value objects directly instead.
 */
final readonly class Camera
{
    public function __construct(private CameraValue $camera)
    {
    }

    public function value(): CameraValue
    {
        return $this->camera;
    }

    public function make(): ?string
    {
        return $this->camera->make;
    }

    public function model(): ?string
    {
        return $this->camera->model;
    }

    public function ownerName(): ?string
    {
        return $this->camera->ownerName;
    }

    public function serialNumber(): ?string
    {
        return $this->camera->serialNumber;
    }

    public function firmware(): ?string
    {
        return $this->camera->firmware;
    }

    public function fileSource(): ?FileSource
    {
        return $this->camera->fileSource;
    }

    public function sensingMethod(): ?SensingMethod
    {
        return $this->camera->sensingMethod;
    }
}
