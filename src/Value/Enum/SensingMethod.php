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
 * Enumerates the sensor sampling methods recognised by the SensingMethod tag in
 * EXIF 3.0 §4.6.3 (shooting conditions), matching the EXIF 2.32 §4.6.3 list.
 */
enum SensingMethod: int
{
    use EnumFromIntStringNullable;

    case NOT_DEFINED             = 1;
    case ONE_CHIP_COLOR_AREA     = 2;
    case TWO_CHIP_COLOR_AREA     = 3;
    case THREE_CHIP_COLOR_AREA   = 4;
    case COLOR_SEQUENTIAL_AREA   = 5;
    case TRILINEAR               = 7;
    case COLOR_SEQUENTIAL_LINEAR = 8;

}
