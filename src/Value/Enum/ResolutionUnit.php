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
 * Enumerates the resolution units recorded by the XResolution/YResolution tags
 * in EXIF 3.0 §4.6.2 (image data structure), continuing the EXIF 2.32 §4.6.2
 * set.
 */
enum ResolutionUnit: int
{
    use EnumFromIntStringNullable;

    case NONE       = 1;
    case INCHES     = 2;
    case CENTIMETER = 3;
}
