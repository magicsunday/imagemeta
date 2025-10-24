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
 * Enumerates subject distance range classifications.
 */
enum SubjectDistanceRange: int
{
    case UNKNOWN = 0;
    case MACRO = 1;
    case CLOSE = 2;
    case DISTANT = 3;

    /**
     * Converts a raw distance range value into the enum.
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
