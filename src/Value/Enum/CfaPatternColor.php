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
 * Enumerates the colour components referenced by the CFA pattern tag.
 */
enum CfaPatternColor: int
{
    case RED      = 0;
    case GREEN    = 1;
    case BLUE     = 2;
    case CYAN     = 3;
    case MAGENTA  = 4;
    case YELLOW   = 5;
    case WHITE    = 6;
    case INFRARED = 7;

    /**
     * Converts raw EXIF values to the corresponding enum when possible.
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
