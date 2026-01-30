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
 * GPS differential correction enumeration.
 *
 * EXIF 3.0 §4.6.6 Table 27 (GPS Attribute Information) defines the GPSDifferential tag
 * which indicates whether differential correction was applied to the GPS receiver.
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::GPS_DIFFERENTIAL
 */
enum GpsDifferential: int
{
    use EnumFromIntStringNullable;

    /**
     * No differential correction applied.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case NO_CORRECTION = 0;

    /**
     * Differential correction applied.
     * EXIF 3.0 §4.6.6 Table 27.
     */
    case DIFFERENTIAL_CORRECTED = 1;
}
