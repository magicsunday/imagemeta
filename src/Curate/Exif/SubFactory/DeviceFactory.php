<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Device;

/**
 * Factory for creating Device value objects from EXIF and QuickTime metadata.
 */
final readonly class DeviceFactory implements SubFactoryInterface
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
        $exif      = $metadata->exifDoc;
        $quickTime = $metadata->quickTime;

        $software = null;

        if ($exif instanceof ParsedExif) {
            $software = $exif->hostComputer();
        }

        $lookup = new QuickTimeLookup($quickTime);

        if ($software === null) {
            $software = $lookup->string(
                'com.apple.quicktime.software',
                'Software',
                'com.apple.quicktime.softwareversion',
                'com.apple.quicktime.software.version',
            );
        }

        return new Device(
            software: $software,
            rawDevelopingSoftware: $exif?->rawDevelopingSoftware(),
            imageEditingSoftware: $exif?->imageEditingSoftware(),
            metadataEditingSoftware: $exif?->metadataEditingSoftware(),
        );
    }
}
