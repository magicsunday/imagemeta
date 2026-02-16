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
 * Enumerates the resolution units recorded by the XResolution/YResolution tags.
 *
 * TIFF 6.0 §8 defines: 1 = no absolute unit, 2 = inch, 3 = centimeter.
 * EXIF 3.0 §4.6.5.1.11 narrows the set for EXIF profiles but all three
 * TIFF values are valid at the model layer.
 */
enum ResolutionUnit: int
{
    use EnumFromIntStringNullable;

    case NONE       = 1;
    case INCHES     = 2;
    case CENTIMETER = 3;
}
