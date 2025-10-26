<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use function is_string;

/**
 * Enumerates in-camera saturation processing levels.
 */
enum Saturation: int
{
    case NORMAL = 0;
    case LOW    = 1;
    case HIGH   = 2;

    /**
     * Converts a raw EXIF saturation value into the enum representation.
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
