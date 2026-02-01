<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Exposure;

/**
 * Factory for creating Exposure value objects from EXIF metadata.
 */
final readonly class ExposureFactory
{
    /**
     * Creates an Exposure value object from EXIF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Exposure Normalised exposure metadata aggregate.
     */
    public function create(Metadata $metadata): Exposure
    {
        $exifDocument = $metadata->exifDoc;

        $exposureProgram = $exifDocument?->exposureProgram();
        $meteringMode    = $exifDocument?->meteringMode();
        $whiteBalance    = $exifDocument?->whiteBalance();
        $flashInfo       = ExifFlash::fromExifValue($exifDocument?->flash() ?? 0);

        return new Exposure(
            iso: $exifDocument?->isoBestEffort(),
            exposureTimeSec: $exifDocument?->exposureTime(),
            fNumber: $exifDocument?->fNumber(),
            exposureBiasEv: $exifDocument?->exposureBias(),
            program: $exposureProgram,
            meteringMode: $meteringMode,
            flash: $flashInfo,
            whiteBalance: $whiteBalance,
            brightnessEv: $exifDocument?->brightnessValue(),
            exposureMode: $exifDocument?->exposureMode(),
            gainControl: $exifDocument?->gainControl(),
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            sharpness: $exifDocument?->sharpness(),
            digitalZoomRatio: $exifDocument?->digitalZoomRatio(),
            shutterSpeedEv: $exifDocument?->shutterSpeedEv(),
            apertureEv: $exifDocument?->apertureEv(),
            isoLatitudeYyy: $exifDocument?->isoLatitudeYyy(),
            isoLatitudeZzz: $exifDocument?->isoLatitudeZzz(),
            exposureIndex: $exifDocument?->exposureIndex(),
            flashEnergy: $exifDocument?->flashEnergy(),
        );
    }
}
