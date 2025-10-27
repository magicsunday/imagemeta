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
 * Describes the camera's exposure program.
 */
enum ExposureProgram: int
{
    use EnumFromIntStringNullable;

    case NOT_DEFINED       = 0;
    case MANUAL            = 1;
    case NORMAL            = 2;
    case APERTURE_PRIORITY = 3;
    case SHUTTER_PRIORITY  = 4;
    case CREATIVE_PROGRAM  = 5;
    case ACTION_PROGRAM    = 6;
    case PORTRAIT_MODE     = 7;
    case LANDSCAPE_MODE    = 8;
    case BULB              = 9;

}
