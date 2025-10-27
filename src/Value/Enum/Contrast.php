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
 * Enumerates in-camera contrast processing levels.
 */
enum Contrast: int
{
    use EnumFromIntStringNullable;

    case NORMAL = 0;
    case SOFT   = 1;
    case HARD   = 2;

}
