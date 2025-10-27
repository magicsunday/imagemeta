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
 * Enumerates EXIF custom rendered values.
 */
enum CustomRendered: int
{
    use EnumFromIntStringNullable;

    case NORMAL_PROCESS = 0;
    case CUSTOM_PROCESS = 1;

}
