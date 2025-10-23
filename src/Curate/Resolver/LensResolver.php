<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

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
        $model = $exifDocument?->lensModel()
            ?? $this->xmpString($xmpDocument, self::NS_AUX, 'LensModel')
            ?? $this->xmpString($xmpDocument, self::NS_AUX, 'Lens');

        $focal = $exifDocument?->focalLengthMm() ?? $this->xmpFloat($xmpDocument, self::NS_EXIF, 'FocalLength');

        if ($model === null && $focal === null) {
            return null;
        }

        return new Lens($model, $focal);
    }
}
