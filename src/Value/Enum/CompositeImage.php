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
 * Enumerates EXIF composite image classifications.
 */
enum CompositeImage: int
{
    case UNKNOWN                 = 0;
    case NOT_COMPOSITE           = 1;
    case GENERAL_COMPOSITE       = 2;
    case CAPTURED_WHILE_SHOOTING = 3;

    /**
     * Converts a raw composite image identifier into the enum.
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
