<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Curate\Xmp\XmpReader;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Camera;

/**
 * Resolves camera information from the available metadata sources.
 */
final readonly class CameraResolver
{

    private const string NS_TIFF = 'http://ns.adobe.com/tiff/1.0/';

    private const string NS_AUX = 'http://ns.adobe.com/exif/1.0/aux/';

    private const string NS_XMP = 'http://ns.adobe.com/xap/1.0/';

    /**
     * Builds a camera value object from the provided metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Camera
    {
        $make     = $exifDocument?->cameraMake() ?? XmpReader::string($xmpDocument, self::NS_TIFF, 'Make');
        $model    = $exifDocument?->cameraModel() ?? XmpReader::string($xmpDocument, self::NS_TIFF, 'Model');
        $owner    = $exifDocument?->ownerName() ?? XmpReader::string($xmpDocument, self::NS_AUX, 'OwnerName');
        $serial   = $exifDocument?->cameraSerialNumber() ?? XmpReader::string($xmpDocument, self::NS_AUX, 'SerialNumber');
        $firmware = XmpReader::string($xmpDocument, self::NS_XMP, 'CreatorTool');

        if ($make === null && $model === null && $owner === null && $serial === null && $firmware === null) {
            return null;
        }

        return new Camera(
            make: $make,
            model: $model,
            ownerName: $owner,
            serialNumber: $serial,
            firmware: $firmware,
            fileSource: null,
            sensingMethod: null,
        );
    }
}
