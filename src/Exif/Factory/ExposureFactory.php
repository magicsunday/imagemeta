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
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;

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
        $flashInfo       = $exifDocument?->flashInfo() ?? ExifFlash::fromExifValue(0);

        $settings = new ExposureSettings(
            iso: $exifDocument?->isoBestEffort(),
            exposureIndex: $exifDocument?->exposureIndex(),
            isoLatitudeYyy: $exifDocument?->isoLatitudeYyy(),
            isoLatitudeZzz: $exifDocument?->isoLatitudeZzz(),
            exposureTimeSec: $exifDocument?->exposureTime(),
            shutterSpeedEv: $exifDocument?->shutterSpeedEv(),
            fNumber: $exifDocument?->fNumber(),
            apertureEv: $exifDocument?->apertureEv(),
            exposureBiasEv: $exifDocument?->exposureBias(),
            brightnessEv: $exifDocument?->brightnessValue(),
        );

        $adjustments = new ExposureAdjustments(
            whiteBalance: $whiteBalance,
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            sharpness: $exifDocument?->sharpness(),
            digitalZoomRatio: $exifDocument?->digitalZoomRatio(),
            gainControl: $exifDocument?->gainControl(),
        );

        return new Exposure(
            settings: $settings,
            adjustments: $adjustments,
            program: $exposureProgram,
            exposureMode: $exifDocument?->exposureMode(),
            meteringMode: $meteringMode,
            flash: $flashInfo,
            flashEnergy: $exifDocument?->flashEnergy(),
        );
    }
}
