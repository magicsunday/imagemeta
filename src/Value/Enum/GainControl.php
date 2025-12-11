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
 * Enumerates the gain control adjustments described for the GainControl tag in
 * EXIF 3.0 §4.6.6.7.41 (shooting conditions).
 */
enum GainControl: int
{
    use EnumFromIntStringNullable;

    case NONE           = 0;
    case LOW_GAIN_UP    = 1;
    case HIGH_GAIN_UP   = 2;
    case LOW_GAIN_DOWN  = 3;
    case HIGH_GAIN_DOWN = 4;
}
