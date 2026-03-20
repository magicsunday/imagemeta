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
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Lens;

/**
 * Factory for creating Lens value objects from EXIF metadata with XMP fallback.
 *
 * Falls back to XMP properties per CIPA DC-X010-2017 Table 14 (exifEX namespace)
 * when EXIF tags are absent.
 */
final readonly class LensFactory
{
    public function __construct(
        private ValueConverters $converters = new ValueConverters(),
    ) {
    }

    /**
     * Creates a Lens value object from EXIF metadata with XMP fallback.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Lens Normalized lens metadata aggregate.
     */
    public function create(Metadata $metadata): Lens
    {
        $exifDocument = $metadata->exifDoc;
        $xmpDocument  = $metadata->selectiveXmpDocument();
        $resolver     = $xmpDocument instanceof XmpDocument ? XmpFallbackResolver::fromDocument($xmpDocument) : null;

        if (!$exifDocument instanceof ParsedExif) {
            return new Lens(
                lensMake: $resolver?->string(ExifTag::LENS_MAKE),
                lensModel: $resolver?->string(ExifTag::LENS_MODEL),
                lensSerialNumber: $resolver?->string(ExifTag::LENS_SERIAL_NUMBER),
                focalLengthMm: $resolver?->float(ExifTag::FOCAL_LENGTH),
                focalLengthIn35mm: $resolver?->int(ExifTag::FOCAL_LENGTH_IN_35MM_FILM),
                maxApertureFNumber: $this->xmpMaxApertureFNumber($resolver),
            );
        }

        $maxApex = $exifDocument->maxApertureApex();
        $maxF    = $maxApex !== null ? $this->converters->apexToFNumber($maxApex) : $this->xmpMaxApertureFNumber($resolver);

        return new Lens(
            lensMake: $exifDocument->lensMake() ?? $resolver?->string(ExifTag::LENS_MAKE),
            lensModel: $exifDocument->lensModel() ?? $resolver?->string(ExifTag::LENS_MODEL),
            lensSerialNumber: $exifDocument->lensSerialNumber() ?? $resolver?->string(ExifTag::LENS_SERIAL_NUMBER),
            focalLengthMm: $exifDocument->focalLengthMm() ?? $resolver?->float(ExifTag::FOCAL_LENGTH),
            focalLengthIn35mm: $exifDocument->focalLength35Mm() ?? $resolver?->int(ExifTag::FOCAL_LENGTH_IN_35MM_FILM),
            maxApertureFNumber: $maxF,
            lensSpecification: $exifDocument->lensSpecification(),
        );
    }

    private function xmpMaxApertureFNumber(?XmpFallbackResolver $resolver): ?float
    {
        $apex = $resolver?->float(ExifTag::MAX_APERTURE_VALUE);

        if ($apex === null) {
            return null;
        }

        return $this->converters->apexToFNumber($apex);
    }
}
