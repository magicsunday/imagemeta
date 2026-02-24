<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the colour component codes used by the CFA pattern tag in EXIF 3.0
 * §4.6.2 (image data structure).
 */
enum CfaPatternColor: int
{
    use EnumFromIntStringNullable;

    case Red      = 0;
    case Green    = 1;
    case Blue     = 2;
    case Cyan     = 3;
    case Magenta  = 4;
    case Yellow   = 5;
    case White    = 6;
    case Infrared = 7;
}
