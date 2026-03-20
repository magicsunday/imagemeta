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
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Sensor;

/**
 * Factory for creating Sensor value objects from EXIF metadata with XMP fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 when EXIF tags are absent.
 */
final readonly class SensorFactory
{
    /**
     * Creates a Sensor value object from EXIF metadata with XMP fallback.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Sensor Normalized sensor metadata aggregate.
     */
    public function create(Metadata $metadata): Sensor
    {
        $exifDocument = $metadata->exifDoc;
        $resolver     = XmpFallbackResolver::fromMetadata($metadata);

        $focalPlaneUnitCode = $exifDocument?->focalPlaneResolutionUnit();
        $focalPlaneUnit     = $focalPlaneUnitCode !== null
            ? ResolutionUnit::tryFrom($focalPlaneUnitCode)
            : $resolver?->enum(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, ResolutionUnit::class);

        return new Sensor(
            pixelPitchUm: null,
            sensorType: null,
            ibis: null,
            cfaPattern: $exifDocument?->cfaPattern(),
            spectralSensitivity: $exifDocument?->spectralSensitivity() ?? $resolver?->string(ExifTag::SPECTRAL_SENSITIVITY),
            oecf: $exifDocument?->oecf(),
            spatialFrequencyResponse: $exifDocument?->spatialFrequencyResponse(),
            focalPlaneXResolution: $exifDocument?->focalPlaneXResolution() ?? $resolver?->float(ExifTag::FOCAL_PLANE_X_RESOLUTION),
            focalPlaneYResolution: $exifDocument?->focalPlaneYResolution() ?? $resolver?->float(ExifTag::FOCAL_PLANE_Y_RESOLUTION),
            focalPlaneResolutionUnit: $focalPlaneUnit,
        );
    }
}
