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
 * Enumerates the white balance modes recorded by EXIF per
 * EXIF 2.32 §4.6.3 and EXIF 3.0 §4.6.3 (shooting conditions).
 */
enum WhiteBalance: int
{
    use EnumFromIntStringNullable;

    case AUTO   = 0;
    case MANUAL = 1;

}
