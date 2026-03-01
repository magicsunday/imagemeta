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
 * Enumerates the CustomRendered tag values.
 *
 * EXIF 3.0 §4.6.6.7.35 (shooting conditions).
 * Values 2–8 are Apple iOS extensions, widely used in iPhone photos.
 */
enum CustomRendered: int
{
    use EnumFromIntStringNullable;

    case NormalProcess      = 0;
    case CustomProcess      = 1;
    case HdrNoOriginalSaved = 2;
    case HdrOriginalSaved   = 3;
    case OriginalForHdr     = 4;
    case Panorama           = 6;
    case PortraitHdr        = 7;
    case Portrait           = 8;
}
