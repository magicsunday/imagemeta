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
 * Enumerates the scene capture classifications defined for the SceneCaptureType
 * tag in EXIF 3.0 §4.6.6.7.40 (shooting conditions), unchanged from EXIF
 * 2.32 §4.6.3.
 */
enum SceneCaptureType: int
{
    use EnumFromIntStringNullable;

    case Standard   = 0;
    case Landscape  = 1;
    case Portrait   = 2;
    case NightScene = 3;
}
