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
 * Enumerates the chroma positioning choices defined for the YCbCrPositioning
 * tag in EXIF 3.0 §4.6.5.1.13 (image data structure), reflecting the TIFF
 * default of CENTERED when absent.
 */
enum YCbCrPositioning: int
{
    use EnumFromIntStringNullable;

    case Centered = 1;
    case CoSited  = 2;
}
