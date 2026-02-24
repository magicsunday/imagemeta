<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * GPS direction reference enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines reference values
 * used for directional GPS tags (GPSImgDirectionRef, GPSTrackRef, GPSDestBearingRef).
 * The allowed values are 'T' (true direction) and 'M' (magnetic direction).
 *
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::GPS_IMG_DIRECTION_REF
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::GPS_TRACK_REF
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::GPS_DEST_BEARING_REF
 */
enum GpsDirectionRef: string
{
    use EnumFromIntStringNullable;

    /**
     * True direction.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case TrueDirection = 'T';

    /**
     * Magnetic direction.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case MagneticDirection = 'M';
}
