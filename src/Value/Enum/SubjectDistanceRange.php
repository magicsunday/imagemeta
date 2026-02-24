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
 * Enumerates the subject distance range classifications assigned to the
 * SubjectDistanceRange tag.
 *
 * EXIF 3.0 §4.6.6.7.46 codifies the four allowed values (0 = unknown, 1 =
 * Macro, 2 = Close view, 3 = Distant view).
 */
enum SubjectDistanceRange: int
{
    use EnumFromIntStringNullable;

    case Unknown = 0;
    case Macro   = 1;
    case Close   = 2;
    case Distant = 3;
}
