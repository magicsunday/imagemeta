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
 * Enumerates subject distance range classifications.
 */
enum SubjectDistanceRange: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN = 0;
    case MACRO   = 1;
    case CLOSE   = 2;
    case DISTANT = 3;

}
