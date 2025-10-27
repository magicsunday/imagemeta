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
 * Enumerates the colour components referenced by the CFA pattern tag.
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
