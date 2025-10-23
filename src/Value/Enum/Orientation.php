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
 * Enumerates the known EXIF orientation values.
 */
enum Orientation: int
{
    case TOP_LEFT = 1;
    case TOP_RIGHT = 2;
    case BOTTOM_RIGHT = 3;
    case BOTTOM_LEFT = 4;
    case LEFT_TOP = 5;
    case RIGHT_TOP = 6;
    case RIGHT_BOTTOM = 7;
    case LEFT_BOTTOM = 8;

    /**
     * Converts a raw EXIF orientation value into an enum instance.
     */
    public static function fromExifValue(?int $value): ?self
    {
        return $value !== null ? self::tryFrom($value) : null;
    }
}
