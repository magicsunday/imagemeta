<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Camera;

/**
 * Factory for creating Camera value objects from EXIF metadata.
 */
final readonly class CameraFactory
{
    /**
     * Creates a Camera value object from EXIF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Camera Normalised camera metadata aggregate.
     */
    public function create(Metadata $metadata): Camera
    {
        $exifDocument = $metadata->exifDoc;

        return new Camera(
            make: $exifDocument?->cameraMake(),
            model: $exifDocument?->cameraModel(),
            ownerName: $exifDocument?->ownerName(),
            firmware: $exifDocument?->cameraFirmware(),
            fileSource: $exifDocument?->fileSource(),
            sensingMethod: $exifDocument?->sensingMethod(),
        );
    }
}
