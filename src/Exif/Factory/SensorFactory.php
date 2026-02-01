<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Sensor;

/**
 * Factory for creating Sensor value objects from EXIF metadata.
 */
final readonly class SensorFactory
{
    /**
     * Creates a Sensor value object from EXIF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Sensor Normalised sensor metadata aggregate.
     */
    public function create(Metadata $metadata): Sensor
    {
        $exifDocument = $metadata->exifDoc;

        $focalPlaneUnit     = null;
        $focalPlaneUnitCode = $exifDocument?->focalPlaneResolutionUnit();

        if ($focalPlaneUnitCode !== null) {
            $focalPlaneUnit = ResolutionUnit::tryFrom($focalPlaneUnitCode);
        }

        return new Sensor(
            pixelPitchUm: null,
            sensorType: null,
            ibis: false,
            cfaPattern: $exifDocument?->cfaPattern(),
            spectralSensitivity: $exifDocument?->spectralSensitivity(),
            oecf: $exifDocument?->oecf(),
            spatialFrequencyResponse: $exifDocument?->spatialFrequencyResponse(),
            focalPlaneXResolution: $exifDocument?->focalPlaneXResolution(),
            focalPlaneYResolution: $exifDocument?->focalPlaneYResolution(),
            focalPlaneResolutionUnit: $focalPlaneUnit,
        );
    }
}
