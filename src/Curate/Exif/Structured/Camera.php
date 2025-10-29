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
 */
final readonly class Camera
{
    public ?string $make;

    public ?string $model;

    public ?string $ownerName;

    public ?string $serialNumber;

    public ?string $firmware;

    public ?FileSource $fileSource;

    public ?SensingMethod $sensingMethod;

    /**
     * @param CameraValue $camera Raw camera value object produced by the parser with unmodified EXIF fields. The mapped
     *                            properties expose the textual identifiers as-is while keeping enum wrappers for file
     *                            source and sensing method.
     */
    public function __construct(CameraValue $camera)
    {
        $this->make          = $camera->make;
        $this->model         = $camera->model;
        $this->ownerName     = $camera->ownerName;
        $this->serialNumber  = $camera->serialNumber;
        $this->firmware      = $camera->firmware;
        $this->fileSource    = $camera->fileSource;
        $this->sensingMethod = $camera->sensingMethod;
    }

    public function make(): ?string
    {
        return $this->make;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function ownerName(): ?string
    {
        return $this->ownerName;
    }

    public function serialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function firmware(): ?string
    {
        return $this->firmware;
    }

    public function fileSource(): ?FileSource
    {
        return $this->fileSource;
    }

    public function sensingMethod(): ?SensingMethod
    {
        return $this->sensingMethod;
    }
}
