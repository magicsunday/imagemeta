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
 * Enumerates gain control adjustments performed by the camera.
 */
enum GainControl: int
{
    case NONE           = 0;
    case LOW_GAIN_UP    = 1;
    case HIGH_GAIN_UP   = 2;
    case LOW_GAIN_DOWN  = 3;
    case HIGH_GAIN_DOWN = 4;

    /**
     * Converts raw EXIF gain control values into the enum.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = is_string($value) ? (int) $value : $value;

        return self::tryFrom($intValue);
    }
}
