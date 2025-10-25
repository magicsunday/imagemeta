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
 * Represents the image colour space information.
 */
enum ColorSpace: int
{
    case SRGB         = 1;
    case ADOBE_RGB    = 2;
    case UNCALIBRATED = 65535;

    /**
     * Creates an enum from the provided raw value when available.
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
