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
 * Enumerates the sensor sampling methods recognised by the SensingMethod tag in
 * EXIF 3.0 §4.6.6.7.31 (SensingMethod).
 *
 * Values 1-5, 7-8 are defined; value 6 is not used in the specification.
 */
enum SensingMethod: int
{
    use EnumFromIntStringNullable;

    case NotDefined            = 1;
    case OneChipColorArea      = 2;
    case TwoChipColorArea      = 3;
    case ThreeChipColorArea    = 4;
    case ColorSequentialArea   = 5;
    case Trilinear             = 7;
    case ColorSequentialLinear = 8;
}
