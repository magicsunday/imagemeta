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
use MagicSunday\ImageMeta\Value\Camera;

/**
 * Resolves camera information from the available metadata sources.
 */
final readonly class CameraResolver
{
    use XmpPropertyAccess;

    private const string NS_TIFF = 'http://ns.adobe.com/tiff/1.0/';
    private const string NS_AUX  = 'http://ns.adobe.com/exif/1.0/aux/';
    private const string NS_XMP  = 'http://ns.adobe.com/xap/1.0/';

    /**
     * Builds a camera value object from the provided metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Camera
    {
        $make   = $exifDocument?->cameraMake() ?? $this->xmpString($xmpDocument, self::NS_TIFF, 'Make');
        $model  = $exifDocument?->cameraModel() ?? $this->xmpString($xmpDocument, self::NS_TIFF, 'Model');
        $serial = $this->xmpString($xmpDocument, self::NS_AUX, 'SerialNumber');
        $tool   = $this->xmpString($xmpDocument, self::NS_XMP, 'CreatorTool');

        if ($make === null && $model === null && $serial === null && $tool === null) {
            return null;
        }

        return new Camera($make, $model, $serial, $tool);
    }
}
