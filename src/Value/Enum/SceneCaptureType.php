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
 * Enumerates known EXIF scene capture type values per
 * EXIF 2.32 §4.6.3 and EXIF 3.0 §4.6.3 (shooting conditions).
 */
enum SceneCaptureType: int
{
    use EnumFromIntStringNullable;

    case STANDARD    = 0;
    case LANDSCAPE   = 1;
    case PORTRAIT    = 2;
    case NIGHT_SCENE = 3;
    case OTHER       = 4;

}
