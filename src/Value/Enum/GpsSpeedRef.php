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
 * GPS speed reference unit enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSSpeedRef tag
 * which indicates the unit used to express the GPS receiver speed of movement.
 * The allowed values are 'K' (kilometers per hour), 'M' (miles per hour), and
 * 'N' (knots).
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_SPEED_REF
 */
enum GpsSpeedRef: string
{
    use EnumFromIntStringNullable;

    /**
     * Kilometers per hour.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case KILOMETERS_PER_HOUR = 'K';

    /**
     * Miles per hour.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case MILES_PER_HOUR = 'M';

    /**
     * Knots (nautical miles per hour).
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case KNOTS = 'N';
}
