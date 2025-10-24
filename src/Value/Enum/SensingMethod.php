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
 * Enumerates image sensor sampling methods.
 */
enum SensingMethod: int
{
    case NOT_DEFINED             = 1;
    case ONE_CHIP_COLOR_AREA     = 2;
    case TWO_CHIP_COLOR_AREA     = 3;
    case THREE_CHIP_COLOR_AREA   = 4;
    case COLOR_SEQUENTIAL_AREA   = 5;
    case TRILINEAR               = 7;
    case COLOR_SEQUENTIAL_LINEAR = 8;

    /**
     * Converts raw sensing method values into the enum.
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
