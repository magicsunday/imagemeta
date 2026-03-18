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
 * GPS altitude reference enumeration.
 *
 * EXIF 3.0 §4.6.7.1.6 (GPSAltitudeRef) defines the altitude reference:
 * - Values 0/1: Ellipsoidal surface reference (EXIF 3.0)
 * - Values 2/3: Sea level reference (backwards compatible with Version 2.32)
 *
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag::GPS_ALTITUDE_REF
 */
enum GpsAltitudeRef: int
{
    use EnumFromIntStringNullable;

    /**
     * Positive ellipsoidal height (at or above ellipsoidal surface).
     * EXIF 3.0 §4.6.7.1.6.
     */
    case AboveEllipsoidalSurface = 0;

    /**
     * Negative ellipsoidal height (below ellipsoidal surface).
     * EXIF 3.0 §4.6.7.1.6.
     */
    case BelowEllipsoidalSurface = 1;

    /**
     * Positive sea level value (at or above sea level).
     * EXIF 3.0 §4.6.7.1.6.
     */
    case AboveSeaLevel           = 2;

    /**
     * Negative sea level value (below sea level).
     * EXIF 3.0 §4.6.7.1.6.
     */
    case BelowSeaLevel           = 3;

    /**
     * Returns whether this reference indicates a negative (below) altitude.
     */
    public function isBelow(): bool
    {
        return $this === self::BelowEllipsoidalSurface || $this === self::BelowSeaLevel;
    }
}
