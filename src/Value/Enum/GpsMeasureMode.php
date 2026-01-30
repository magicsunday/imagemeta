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
 * GPS measurement mode enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSMeasureMode tag
 * which indicates the GPS measurement mode (2D or 3D).
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_MEASURE_MODE
 */
enum GpsMeasureMode: string
{
    use EnumFromIntStringNullable;

    /**
     * 2-dimensional measurement.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case TWO_DIMENSIONAL = '2';

    /**
     * 3-dimensional measurement.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case THREE_DIMENSIONAL = '3';
}
