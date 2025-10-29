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
 * Enumerates resolution units for X/Y resolution tags per
 * EXIF 2.32 §4.6.2 and EXIF 3.0 §4.6.2 (image data structure).
 */
enum ResolutionUnit: int
{
    use EnumFromIntStringNullable;

    case NONE       = 1;
    case INCHES     = 2;
    case CENTIMETER = 3;

}
