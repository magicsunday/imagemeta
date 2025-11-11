<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Lens;

/**
 * Factory for creating Lens value objects from EXIF metadata.
 */
final readonly class LensFactory
{
    /**
     * Creates a Lens value object from EXIF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Lens Normalised lens metadata aggregate.
     */
    public function create(Metadata $metadata): Lens
    {
        $exifDocument = $metadata->exifDoc;

        if (!$exifDocument instanceof ParsedExif) {
            return new Lens(
                lensMake: null,
                lensModel: null,
                lensSerialNumber: null,
                focalLengthMm: null,
                focalLengthIn35mm: null,
                maxApertureFNumber: null,
            );
        }

        $maxApex = $exifDocument->maxApertureApex();
        $maxF    = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        return new Lens(
            lensMake: $exifDocument->lensMake(),
            lensModel: $exifDocument->lensModel(),
            lensSerialNumber: $exifDocument->lensSerialNumber(),
            focalLengthMm: $exifDocument->focalLengthMm(),
            focalLengthIn35mm: $exifDocument->focalLength35Mm(),
            maxApertureFNumber: $maxF,
            lensSpecification: $exifDocument->lensSpecification(),
        );
    }
}
