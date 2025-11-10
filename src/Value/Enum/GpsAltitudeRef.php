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
 * GPS altitude reference enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSAltitudeRef tag
 * which indicates whether the altitude is above or below sea level.
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_ALTITUDE_REF
 */
enum GpsAltitudeRef: int
{
    use EnumFromIntStringNullable;

    /**
     * Above sea level.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case ABOVE_SEA_LEVEL = 0;

    /**
     * Below sea level.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case BELOW_SEA_LEVEL = 1;
}
