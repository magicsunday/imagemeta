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
 * Describes the camera's exposure program.
 */
enum ExposureProgram: int
{
    case NOT_DEFINED       = 0;
    case MANUAL            = 1;
    case NORMAL            = 2;
    case APERTURE_PRIORITY = 3;
    case SHUTTER_PRIORITY  = 4;
    case CREATIVE          = 5;
    case ACTION            = 6;
    case PORTRAIT          = 7;
    case LANDSCAPE         = 8;
    case BULB              = 9;

    /**
     * Maps a numeric value to the corresponding enum case when possible.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $intValue = is_int($value) ? $value : (int) $value;

        return self::tryFrom($intValue);
    }
}
