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
 * Enumerates the flash mode bits carried in the Flash tag according to EXIF 3.0
 * §4.6.6.7.21 (Flash).
 */
enum FlashMode: int
{
    use EnumFromIntStringNullable;

    case Unknown            = 0;
    case CompulsoryFire     = 1;
    case CompulsorySuppress = 2;
    case Auto               = 3;
}
