<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;

/**
 * GPS distance reference unit enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSDestDistanceRef tag
 * which indicates the unit used to express the distance to the destination point.
 * The allowed values are 'K' (kilometers), 'M' (miles), and 'N' (nautical miles).
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_DEST_DISTANCE_REF
 */
enum GpsDistanceRef: string
{
    use EnumFromIntStringNullable;

    /**
     * Kilometers.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case KILOMETERS = 'K';

    /**
     * Miles.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case MILES = 'M';

    /**
     * Nautical miles.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case NAUTICAL_MILES = 'N';
}
