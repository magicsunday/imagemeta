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
 * Enumerates the photometric interpretations listed for the
 * PhotometricInterpretation tag in EXIF 3.0 §4.6.2 (image data structure),
 * aligning with EXIF 2.32 §4.6.2 and the TIFF 6.0 §8 definitions.
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
