<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use function is_int;

/**
 * Enumerates the white balance modes recorded by EXIF.
 */
enum WhiteBalance: int
{
    case AUTO   = 0;
    case MANUAL = 1;

    /**
     * Converts a raw value to an enum case if supported.
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
