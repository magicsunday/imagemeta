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
 * Sensitivity type enumeration for ISO sensitivity values.
 *
 * EXIF 3.0 §4.6.6.7.7 Table 14 defines the SensitivityType tag which indicates
 * which ISO 12232 parameter is encoded by the PhotographicSensitivity tag.
 *
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::SENSITIVITY_TYPE
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::PHOTOGRAPHIC_SENSITIVITY
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::STANDARD_OUTPUT_SENSITIVITY
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::RECOMMENDED_EXPOSURE_INDEX
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::ISO_SPEED
 */
enum SensitivityType: int
{
    use EnumFromIntStringNullable;

    /**
     * Unknown sensitivity type.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case Unknown = 0;

    /**
     * Standard output sensitivity (SOS).
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case StandardOutputSensitivity = 1;

    /**
     * Recommended exposure index (REI).
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case RecommendedExposureIndex = 2;

    /**
     * ISO speed.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case IsoSpeed = 3;

    /**
     * Standard output sensitivity and recommended exposure index.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case SosAndRei = 4;

    /**
     * Standard output sensitivity and ISO speed.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case SosAndIso = 5;

    /**
     * Recommended exposure index and ISO speed.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case ReiAndIso = 6;

    /**
     * Standard output sensitivity, recommended exposure index, and ISO speed.
     * EXIF 3.0 §4.6.6.7.7 Table 14.
     */
    case SosAndReiAndIso = 7;
}
