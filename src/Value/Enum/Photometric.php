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
 * PhotometricInterpretation tag as defined in EXIF 3.0 §4.6.5.1.5.
 *
 * JPEG compressed data uses the JPEG marker instead of this tag. Only RGB (2)
 * and YCbCr (6) values are valid; all other codes are reserved by the
 * specification (EXIF 2.32 §4.6.5.1.5).
 */
enum Photometric: int
{
    use EnumFromIntStringNullable;

    case RGB   = 2;
    case YCBCR = 6;
}
