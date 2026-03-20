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
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;

/**
 * Factory for creating Exposure value objects from EXIF metadata with XMP fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 Table 13 when EXIF tags are absent.
 */
final readonly class ExposureFactory
{
    /**
     * Creates an Exposure value object from EXIF metadata with XMP fallback.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Exposure Normalized exposure metadata aggregate.
     */
    public function create(Metadata $metadata): Exposure
    {
        $exifDocument = $metadata->exifDoc;
        $resolver     = XmpFallbackResolver::fromMetadata($metadata);

        $exposureProgram = $exifDocument?->exposureProgram() ?? $resolver?->enum(ExifTag::EXPOSURE_PROGRAM, ExposureProgram::class);
        $meteringMode    = $exifDocument?->meteringMode() ?? $resolver?->enum(ExifTag::METERING_MODE, MeteringMode::class);
        $whiteBalance    = $exifDocument?->whiteBalance() ?? $resolver?->enum(ExifTag::WHITE_BALANCE, WhiteBalance::class);
        $flashInfo       = $exifDocument?->flashInfo();

        $settings = new ExposureSettings(
            iso: $exifDocument?->isoBestEffort() ?? $resolver?->int(ExifTag::PHOTOGRAPHIC_SENSITIVITY),
            exposureIndex: $exifDocument?->exposureIndex() ?? $resolver?->float(ExifTag::EXPOSURE_INDEX),
            isoLatitudeYyy: $exifDocument?->isoSpeedLatitudeYyy() ?? $resolver?->int(ExifTag::ISO_SPEED_LATITUDE_YYY),
            isoLatitudeZzz: $exifDocument?->isoSpeedLatitudeZzz() ?? $resolver?->int(ExifTag::ISO_SPEED_LATITUDE_ZZZ),
            exposureTimeSec: $exifDocument?->exposureTime() ?? $resolver?->float(ExifTag::EXPOSURE_TIME),
            shutterSpeedEv: $exifDocument?->shutterSpeedValue() ?? $resolver?->float(ExifTag::SHUTTER_SPEED_VALUE),
            fNumber: $exifDocument?->fNumber() ?? $resolver?->float(ExifTag::F_NUMBER),
            apertureEv: $exifDocument?->apertureValue() ?? $resolver?->float(ExifTag::APERTURE_VALUE),
            exposureBiasEv: $exifDocument?->exposureBias() ?? $resolver?->float(ExifTag::EXPOSURE_BIAS_VALUE),
            brightnessEv: $exifDocument?->brightnessValue() ?? $resolver?->float(ExifTag::BRIGHTNESS_VALUE),
        );

        $adjustments = new ExposureAdjustments(
            whiteBalance: $whiteBalance,
            contrast: $exifDocument?->contrast() ?? $resolver?->enum(ExifTag::CONTRAST, Contrast::class),
            saturation: $exifDocument?->saturation() ?? $resolver?->enum(ExifTag::SATURATION, Saturation::class),
            sharpness: $exifDocument?->sharpness() ?? $resolver?->enum(ExifTag::SHARPNESS, Sharpness::class),
            digitalZoomRatio: $exifDocument?->digitalZoomRatio() ?? $resolver?->float(ExifTag::DIGITAL_ZOOM_RATIO),
            gainControl: $exifDocument?->gainControl() ?? $resolver?->enum(ExifTag::GAIN_CONTROL, GainControl::class),
        );

        return new Exposure(
            settings: $settings,
            adjustments: $adjustments,
            program: $exposureProgram,
            exposureMode: $exifDocument?->exposureMode() ?? $resolver?->enum(ExifTag::EXPOSURE_MODE, ExposureMode::class),
            meteringMode: $meteringMode,
            flash: $flashInfo,
            flashEnergy: $exifDocument?->flashEnergy() ?? $resolver?->float(ExifTag::FLASH_ENERGY),
        );
    }
}
