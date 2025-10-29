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
 * Describes whether the flash function is present on the camera (EXIF 2.32 §4.6.4 / EXIF 3.0 §4.6.4).
 */
enum FlashFunction: int
{
    use EnumFromIntStringNullable;

    case PRESENT = 0;
    case ABSENT  = 1;
}
