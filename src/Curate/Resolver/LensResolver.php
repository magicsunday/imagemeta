<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Core\ValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Lens;

/**
 * Resolves lens metadata from EXIF and XMP data sets.
 */
final readonly class LensResolver
{
    use XmpPropertyAccess;

    private const string NS_AUX  = 'http://ns.adobe.com/exif/1.0/aux/';
    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds a lens value object from the provided metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Lens
    {
        $make  = $exifDocument?->lensMake() ?? $this->xmpString($xmpDocument, self::NS_AUX, 'LensMake');
        $model = $exifDocument?->lensModel()
            ?? $this->xmpString($xmpDocument, self::NS_AUX, 'LensModel')
            ?? $this->xmpString($xmpDocument, self::NS_AUX, 'Lens');
        $serial  = $exifDocument?->lensSerialNumber() ?? $this->xmpString($xmpDocument, self::NS_AUX, 'LensSerialNumber');
        $focal   = $exifDocument?->focalLengthMm() ?? $this->xmpFloat($xmpDocument, self::NS_EXIF, 'FocalLength');
        $focal35 = $exifDocument?->focalLength35Mm();

        $maxApex     = $exifDocument?->maxApertureApex();
        $maxAperture = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        if ($make === null && $model === null && $serial === null && $focal === null && $focal35 === null) {
            return null;
        }

        return new Lens(
            lensMake: $make,
            lensModel: $model,
            lensSerialNumber: $serial,
            focalLengthMm: $focal,
            focalLengthIn35mm: $focal35,
            maxApertureFNumber: $maxAperture,
            lensInfo: null,
        );
    }
}
