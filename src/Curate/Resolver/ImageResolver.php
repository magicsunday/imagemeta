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
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image;

/**
 * Resolves image level metadata such as orientation and colour space.
 */
final readonly class ImageResolver
{
    use XmpPropertyAccess;

    private const string NS_TIFF = 'http://ns.adobe.com/tiff/1.0/';
    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds an image value object from the available metadata sources.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Image
    {
        $orientation = Orientation::fromExifValue($exifDocument?->orientation());
        if ($orientation === null) {
            $orientation = Orientation::fromExifValue($this->xmpInt($xmpDocument, self::NS_TIFF, 'Orientation'));
        }

        $colorSpaceValue = $this->xmpInt($xmpDocument, self::NS_EXIF, 'ColorSpace');
        $colorSpace      = ColorSpace::fromExifValue($colorSpaceValue);

        if ($orientation === null && $colorSpace === null) {
            return null;
        }

        return new Image($orientation, $colorSpace);
    }
}
