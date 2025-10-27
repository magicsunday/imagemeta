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
 * Enumerates TIFF/EXIF photometric interpretations defined in EXIF 3.0.
 */
enum Photometric: int
{
    use EnumFromIntStringNullable;

    case WHITE_IS_ZERO     = 0;
    case BLACK_IS_ZERO     = 1;
    case RGB               = 2;
    case PALETTE_COLOR     = 3;
    case TRANSPARENCY_MASK = 4;
    case CMYK              = 5;
    case YCBCR             = 6;
    case CIELAB            = 8;
    case ICCLAB            = 9;

}
