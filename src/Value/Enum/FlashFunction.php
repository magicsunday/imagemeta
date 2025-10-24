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
 * Describes whether the flash function is present on the camera.
 */
enum FlashFunction: int
{
    case PRESENT = 0;
    case ABSENT  = 1;

    /**
     * Converts the flash bit field into an enum instance.
     */
    public static function fromFlashBits(int $value): ?self
    {
        $functionBit = ($value >> 5) & 0x01;

        return self::tryFrom($functionBit);
    }
}
