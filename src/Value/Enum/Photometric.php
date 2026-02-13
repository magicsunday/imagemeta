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
 * PhotometricInterpretation tag as defined in EXIF 3.0 §4.6.5.1.5
 * and extended by DNG 1.7.1.0 for CFA, LinearRaw, Depth and Semantic Mask IFDs.
 *
 * JPEG compressed data uses the JPEG marker instead of this tag. Standard EXIF
 * defines RGB (2) and YCbCr (6); DNG adds CFA (32803), LinearRaw (34892),
 * Depth (51177) and PhotometricMask (52527).
 */
enum Photometric: int
{
    use EnumFromIntStringNullable;

    case RGB              = 2;
    case YCBCR            = 6;
    case CFA              = 32803;
    case LINEAR_RAW       = 34892;
    case DEPTH            = 51177;
    case PHOTOMETRIC_MASK = 52527;
}
