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
 * Enumerates the chroma positioning choices defined for the YCbCrPositioning
 * tag in EXIF 3.0 §4.6.2 (image data structure), continuing the EXIF 2.32
 * §4.6.2 mapping.
 */
enum YCbCrPositioning: int
{
    use EnumFromIntStringNullable;

    case CENTERED = 1;
    case CO_SITED = 2;

}
