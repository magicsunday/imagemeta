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
 * Enumerates light source identifiers as defined by EXIF.
 */
enum LightSource: int
{
    case UNKNOWN                = 0;
    case DAYLIGHT               = 1;
    case FLUORESCENT            = 2;
    case TUNGSTEN               = 3;
    case FLASH                  = 4;
    case FINE_WEATHER           = 9;
    case CLOUDY                 = 10;
    case SHADE                  = 11;
    case DAYLIGHT_FLUORESCENT   = 12;
    case DAY_WHITE_FLUORESCENT  = 13;
    case COOL_WHITE_FLUORESCENT = 14;
    case WHITE_FLUORESCENT      = 15;

    /**
     * Warm white fluorescent light source.
     */
    case WARM_WHITE_FLUORESCENT = 16;
    case STANDARD_LIGHT_A       = 17;
    case STANDARD_LIGHT_B       = 18;
    case STANDARD_LIGHT_C       = 19;
    case D55                    = 20;
    case D65                    = 21;
    case D75                    = 22;
    case D50                    = 23;
    case ISO_STUDIO_TUNGSTEN    = 24;
    case OTHER                  = 255;

    /**
     * Converts a raw EXIF light source value into the corresponding enum.
     *
     * @param int|null $value Raw EXIF numeric light source value.
     */
    public static function fromExifValue(?int $value): ?self
    {
        return $value !== null ? self::tryFrom($value) : null;
    }
}
