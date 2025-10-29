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
 * Enumerates the saturation processing levels associated with the Saturation
 * tag in EXIF 3.0 §4.6.3 (shooting conditions), identical to EXIF 2.32
 * §4.6.3.
 */
enum Saturation: int
{
    use EnumFromIntStringNullable;

    case NORMAL = 0;
    case LOW    = 1;
    case HIGH   = 2;
}
