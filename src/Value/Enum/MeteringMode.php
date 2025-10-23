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
 * Defines the metering mode used to determine exposure.
 */
enum MeteringMode: int
{
    case UNKNOWN = 0;
    case AVERAGE = 1;
    case CENTER_WEIGHTED_AVERAGE = 2;
    case SPOT = 3;
    case MULTI_SPOT = 4;
    case PATTERN = 5;
    case PARTIAL = 6;
    case OTHER = 255;

    /**
     * Attempts to convert the provided numeric value into an enum case.
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
