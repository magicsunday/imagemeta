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
 * Enumerates the colour component codes used by the CFA pattern tag in EXIF 3.0
 * §4.6.2 (image data structure).
 */
enum CfaPatternColor: int
{
    use EnumFromIntStringNullable;

    case RED      = 0;
    case GREEN    = 1;
    case BLUE     = 2;
    case CYAN     = 3;
    case MAGENTA  = 4;
    case YELLOW   = 5;
    case WHITE    = 6;
    case INFRARED = 7;
}
