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
 * GPS receiver status enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSStatus tag
 * which indicates the status of the GPS receiver when the image was recorded.
 *
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::GPS_STATUS
 */
enum GpsStatus: string
{
    use EnumFromIntStringNullable;

    /**
     * Measurement in progress.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case MeasurementInProgress = 'A';

    /**
     * Measurement interoperability (void).
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case MeasurementVoid       = 'V';
}
