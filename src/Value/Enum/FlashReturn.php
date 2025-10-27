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
 * Indicates the strobe return detection status encoded in the EXIF Flash tag.
 */
enum FlashReturn: int
{
    use EnumFromIntStringNullable;

    case NO_STROBE_DETECTION = 0;
    case RETURN_NOT_DETECTED = 2;
    case RETURN_DETECTED     = 3;

    /**
     * Creates an enum instance from the flash bit field.
     */
    public static function fromFlashBits(int $value): ?self
    {
        $returnBits = ($value >> 1) & 0x03;

        return self::tryFrom($returnBits);
    }
}
