<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;

/**
 * Factory for creating Camera value objects from EXIF metadata with XMP and QuickTime fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 Tables 5 and 14 when EXIF tags are absent,
 * then to QuickTime metadata keys, and finally to RIFF EXIF sub-chunks for make and model.
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
        $exifDocument    = $metadata->exifDoc;
        $resolver        = XmpFallbackResolver::fromMetadata($metadata);
        $quickTimeLookup = $metadata->quickTimeLookup();

        $riffLookup = $metadata->riffInfoLookup();
        $nikonAvi   = $metadata->nikonAviLookup();
        $olympusAvi = $metadata->olympusAviLookup();

        return new Camera(
            make: $exifDocument?->cameraMake() ?? $resolver?->string(ExifTag::MAKE) ?? $quickTimeLookup->string('com.apple.quicktime.make') ?? $riffLookup->exifMake() ?? $nikonAvi->make() ?? $olympusAvi->make(),
            model: $exifDocument?->cameraModel() ?? $resolver?->string(ExifTag::MODEL) ?? $quickTimeLookup->string('com.apple.quicktime.model') ?? $riffLookup->exifModel() ?? $nikonAvi->model() ?? $olympusAvi->model(),
            ownerName: $exifDocument?->ownerName() ?? $resolver?->string(ExifTag::CAMERA_OWNER_NAME),
            firmware: $exifDocument?->cameraFirmware() ?? $resolver?->string(ExifTag::SOFTWARE),
            fileSource: $exifDocument?->fileSource() ?? $resolver?->enum(ExifTag::FILE_SOURCE, FileSource::class),
            sensingMethod: $exifDocument?->sensingMethod() ?? $resolver?->enum(ExifTag::SENSING_METHOD, SensingMethod::class),
        );
    }
}
