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
 * Indicates the strobe return detection status encoded in the Flash tag per
 * EXIF 3.0 §4.6.6.7.21 (Flash).
 */
enum FlashReturn: int
{
    use EnumFromIntStringNullable;

    case NoStrobeDetection = 0;
    case Reserved          = 1;
    case ReturnNotDetected = 2;
    case ReturnDetected    = 3;
}
