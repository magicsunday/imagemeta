<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Defines the metering modes listed for the MeteringMode tag in EXIF 3.0
 * §4.6.6.7.19 (MeteringMode).
 */
enum MeteringMode: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN                 = 0;
    case AVERAGE                 = 1;
    case CENTER_WEIGHTED_AVERAGE = 2;
    case SPOT                    = 3;
    case MULTI_SPOT              = 4;
    case PATTERN                 = 5;
    case PARTIAL                 = 6;
    case OTHER                   = 255;
}
