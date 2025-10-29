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
 * Enumerates the flash mode encoded inside the EXIF Flash tag (EXIF 2.32 §4.6.4 / EXIF 3.0 §4.6.4).
 */
enum FlashMode: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN             = 0;
    case COMPULSORY_FIRE     = 1;
    case COMPULSORY_SUPPRESS = 2;
    case AUTO                = 3;
}
