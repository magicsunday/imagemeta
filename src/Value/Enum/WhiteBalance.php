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
 * Enumerates the white balance modes reported by the WhiteBalance tag in EXIF
 * 3.0 §4.6.6.7.37 (shooting conditions).
 */
enum WhiteBalance: int
{
    use EnumFromIntStringNullable;

    case Auto   = 0;
    case Manual = 1;
}
