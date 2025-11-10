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
 * Sensitivity type enumeration for ISO sensitivity values.
 *
 * EXIF 3.0 §4.6.4 Table 10 defines the SensitivityType tag which indicates
 * which ISO sensitivity value (if any) is valid and what type it represents.
 * EXIF 2.3 §4.6.6 introduced this tag to disambiguate ISO speed representations.
 *
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::SENSITIVITY_TYPE
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::PHOTOGRAPHIC_SENSITIVITY
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::STANDARD_OUTPUT_SENSITIVITY
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::RECOMMENDED_EXPOSURE_INDEX
 * @see \MagicSunday\ImageMeta\Model\Exif\ExifTag::ISO_SPEED
 */
enum SensitivityType: int
{
    use EnumFromIntStringNullable;

    /**
     * Unknown sensitivity type.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case UNKNOWN = 0;

    /**
     * Standard output sensitivity (SOS).
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case STANDARD_OUTPUT_SENSITIVITY = 1;

    /**
     * Recommended exposure index (REI).
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case RECOMMENDED_EXPOSURE_INDEX = 2;

    /**
     * ISO speed.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case ISO_SPEED = 3;

    /**
     * Standard output sensitivity and recommended exposure index.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case SOS_AND_REI = 4;

    /**
     * Standard output sensitivity and ISO speed.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case SOS_AND_ISO = 5;

    /**
     * Recommended exposure index and ISO speed.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case REI_AND_ISO = 6;

    /**
     * Standard output sensitivity, recommended exposure index, and ISO speed.
     * EXIF 3.0 §4.6.4 Table 10.
     */
    case SOS_AND_REI_AND_ISO = 7;
}
