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
 * Represents the colour space encodings described for the ColorSpace tag.
 *
 * EXIF 3.0 §4.6.6.2.1 (ColorSpace).
 * Values 2, 65533, and 65534 are non-standard but widely used by camera manufacturers.
 */
enum ColorSpace: int
{
    use EnumFromIntStringNullable;

    case Srgb         = 1;
    case AdobeRgb     = 2;
    case WideGamutRgb = 65533;
    case IccProfile   = 65534;
    case Uncalibrated = 65535;
}
