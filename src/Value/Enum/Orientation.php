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
 * Enumerates the orientation values defined for the Orientation tag in EXIF
 * 3.0 §4.6.5.1.6 (image data structure), inherited from EXIF 2.32
 * §4.6.5.1.6.
 */
enum Orientation: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN      = 0;
    case TOP_LEFT     = 1;
    case TOP_RIGHT    = 2;
    case BOTTOM_RIGHT = 3;
    case BOTTOM_LEFT  = 4;
    case LEFT_TOP     = 5;
    case RIGHT_TOP    = 6;
    case RIGHT_BOTTOM = 7;
    case LEFT_BOTTOM  = 8;
}
