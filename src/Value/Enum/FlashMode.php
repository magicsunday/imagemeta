<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;

/**
 * Enumerates the flash mode encoded inside the EXIF Flash tag.
 */
enum FlashMode: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN             = 0;
    case COMPULSORY_FIRE     = 1;
    case COMPULSORY_SUPPRESS = 2;
    case AUTO                = 3;

    /**
     * Builds an enum instance from the bit field representation.
     */
    public static function fromFlashBits(int $value): ?self
    {
        $modeBits = ($value >> 3) & 0x03;

        return self::tryFrom($modeBits);
    }
}
