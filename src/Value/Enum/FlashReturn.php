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
 * Indicates the strobe return detection status encoded in the EXIF Flash tag.
 */
enum FlashReturn: int
{
    case NO_STROBE_DETECTION = 0;
    case RESERVED = 1;
    case NOT_DETECTED = 2;
    case DETECTED = 3;

    /**
     * Creates an enum instance from the flash bit field.
     */
    public static function fromFlashBits(int $value): ?self
    {
        $returnBits = ($value >> 1) & 0x03;

        return self::tryFrom($returnBits);
    }
}
