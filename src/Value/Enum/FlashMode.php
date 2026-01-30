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
 * Enumerates the flash mode bits carried in the Flash tag according to EXIF 3.0
 * §4.6.6.7.21 (Flash).
 */
enum FlashMode: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN             = 0;
    case COMPULSORY_FIRE     = 1;
    case COMPULSORY_SUPPRESS = 2;
    case AUTO                = 3;
}
