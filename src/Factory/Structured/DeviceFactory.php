<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory\Structured;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Device;

/**
 * Factory for creating Device value objects from EXIF and QuickTime metadata.
 */
final readonly class DeviceFactory
{
    /**
     * Creates a Device value object from EXIF and QuickTime metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Device Device value object describing capture hardware and software.
     */
    public function create(Metadata $metadata): Device
    {
        $exifDocument = $metadata->exifDoc;
        $software     = $exifDocument?->software() ?? $exifDocument?->hostComputer();

        if (($software === null) && ($metadata->quickTime instanceof QuickTimeMeta)) {
            $lookup   = $metadata->quickTimeLookup();
            $software = $lookup->string(
                'com.apple.quicktime.software',
                'Software',
                'com.apple.quicktime.softwareversion',
                'com.apple.quicktime.software.version',
            );
        }

        return new Device(
            software: $software,
            rawDevelopingSoftware: $exifDocument?->rawDevelopingSoftware(),
            imageEditingSoftware: $exifDocument?->imageEditingSoftware(),
            metadataEditingSoftware: $exifDocument?->metadataEditingSoftware(),
        );
    }
}
