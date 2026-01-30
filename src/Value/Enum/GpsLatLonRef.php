<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * GPS latitude/longitude hemisphere reference enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the reference
 * values for GPS latitude and longitude hemisphere indicators.
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_LATITUDE_REF
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_LONGITUDE_REF
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_DEST_LATITUDE_REF
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_DEST_LONGITUDE_REF
 */
enum GpsLatLonRef: string
{
    use EnumFromIntStringNullable;

    /**
     * North latitude.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case NORTH = 'N';

    /**
     * South latitude.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case SOUTH = 'S';

    /**
     * East longitude.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case EAST = 'E';

    /**
     * West longitude.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case WEST = 'W';
}
