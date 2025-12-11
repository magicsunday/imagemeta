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
 * Indicates the strobe return detection status encoded in the Flash tag per
 * EXIF 3.0 §4.6.6.7.21 (Flash).
 */
enum FlashReturn: int
{
    use EnumFromIntStringNullable;

    case NO_STROBE_DETECTION = 0;
    case RESERVED            = 1;
    case RETURN_NOT_DETECTED = 2;
    case RETURN_DETECTED     = 3;
}
