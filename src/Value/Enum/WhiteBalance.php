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
 * Enumerates the white balance modes reported by the WhiteBalance tag in EXIF
 * 3.0 §4.6.3 (shooting conditions), reflecting the EXIF 2.32 §4.6.3 options.
 */
enum WhiteBalance: int
{
    use EnumFromIntStringNullable;

    case AUTO   = 0;
    case MANUAL = 1;
}
