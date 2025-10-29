<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Camera as CameraValue;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

/**
 * Groups camera and host device information into a unified view.
 */
final readonly class CameraMetadata
{
    public ?string $make;

    public ?string $model;

    public ?string $ownerName;

    public ?string $serialNumber;

    public ?string $firmware;

    public ?FileSource $fileSource;

    public ?SensingMethod $sensingMethod;

    public Device $device;

    public function __construct(
        CameraValue $camera,
        Device $device,
    ) {
        $this->make          = $camera->make;
        $this->model         = $camera->model;
        $this->ownerName     = $camera->ownerName;
        $this->serialNumber  = $camera->serialNumber;
        $this->firmware      = $camera->firmware;
        $this->fileSource    = $camera->fileSource;
        $this->sensingMethod = $camera->sensingMethod;
        $this->device        = $device;
    }
}
