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
 * Defines the metering modes listed for the MeteringMode tag in EXIF 3.0
 * §4.6.6.7.19 (MeteringMode).
 */
enum MeteringMode: int
{
    use EnumFromIntStringNullable;

    case Unknown               = 0;
    case Average               = 1;
    case CenterWeightedAverage = 2;
    case Spot                  = 3;
    case MultiSpot             = 4;
    case Pattern               = 5;
    case Partial               = 6;
    case Other                 = 255;
}
