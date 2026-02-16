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
 * PhotometricInterpretation tag as defined in TIFF 6.0 §8, EXIF 3.0 §4.6.5.1.5
 * and extended by DNG 1.7.1.0 for CFA, LinearRaw, Depth and Semantic Mask IFDs.
 *
 * TIFF 6.0 baseline values: WhiteIsZero (0), BlackIsZero (1), RGB (2),
 * PaletteColor (3), TransparencyMask (4). Extensions add Separated (5),
 * YCbCr (6), CIELab (8). DNG adds CFA (32803), LinearRaw (34892),
 * Depth (51177) and PhotometricMask (52527).
 */
enum Photometric: int
{
    use EnumFromIntStringNullable;

    case WHITE_IS_ZERO     = 0;
    case BLACK_IS_ZERO     = 1;
    case RGB               = 2;
    case PALETTE_COLOR     = 3;
    case TRANSPARENCY_MASK = 4;
    case SEPARATED         = 5;
    case YCBCR             = 6;
    case CIELAB            = 8;
    case CFA               = 32803;
    case LINEAR_RAW        = 34892;
    case DEPTH             = 51177;
    case PHOTOMETRIC_MASK  = 52527;
}
