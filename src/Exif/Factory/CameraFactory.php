<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Camera;

/**
 * Factory for creating Camera value objects from EXIF metadata with XMP fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 Tables 5 and 14 when EXIF tags are absent.
 */
final readonly class CameraFactory
{
    /**
     * Creates a Camera value object from EXIF metadata with XMP fallback.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Camera Normalized camera metadata aggregate.
     */
    public function create(Metadata $metadata): Camera
    {
        $exifDocument = $metadata->exifDoc;
        $xmpDocument  = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $resolver     = $xmpDocument instanceof XmpDocument ? XmpFallbackResolver::fromDocument($xmpDocument) : null;

        return new Camera(
            make: $exifDocument?->cameraMake() ?? $resolver?->string(ExifTag::MAKE),
            model: $exifDocument?->cameraModel() ?? $resolver?->string(ExifTag::MODEL),
            ownerName: $exifDocument?->ownerName() ?? $resolver?->string(ExifTag::CAMERA_OWNER_NAME),
            firmware: $exifDocument?->cameraFirmware() ?? $resolver?->string(ExifTag::SOFTWARE),
            fileSource: $exifDocument?->fileSource(),
            sensingMethod: $exifDocument?->sensingMethod(),
        );
    }
}
