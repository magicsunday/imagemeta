<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

/**
 * Central registry of XMP namespace URIs used throughout the library.
 */
enum XmpNamespace: string
{
    case DC = 'http://purl.org/dc/elements/1.1/';

    case XAP = 'http://ns.adobe.com/xap/1.0/';

    case XAP_RIGHTS = 'http://ns.adobe.com/xap/1.0/rights/';

    case XAP_MM = 'http://ns.adobe.com/xap/1.0/mm/';

    case EXIF = 'http://ns.adobe.com/exif/1.0/';

    case TIFF = 'http://ns.adobe.com/tiff/1.0/';

    case PHOTOSHOP = 'http://ns.adobe.com/photoshop/1.0/';

    case LIGHTROOM = 'http://ns.adobe.com/lightroom/1.0/';

    case IPTC_CORE = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';

    case GOOGLE_PANORAMA = 'http://ns.google.com/photos/1.0/panorama/';

    case GOOGLE_DEPTH_MAP = 'http://ns.google.com/photos/1.0/depthmap/';

    case ST_AREA = 'http://ns.adobe.com/xmp/sType/Area#';

    case ST_DIMENSIONS = 'http://ns.adobe.com/xmp/sType/Dimensions#';

    case MWG_REGIONS = 'http://www.metadataworkinggroup.com/schemas/regions/';

    case APPLE_FACEINFO = 'http://ns.apple.com/faceinfo/1.0/';
}
