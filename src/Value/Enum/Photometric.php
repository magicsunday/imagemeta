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
 * Enumerates the photometric interpretations allowed for the
 * PhotometricInterpretation tag in EXIF 3.0 §4.6.5.1.5 (image data structure),
 * aligning with EXIF 2.32 §4.6.5.1.5.
 *
 * Only RGB (2) and YCbCr (6) are defined; other codes are reserved and will be
 * rejected.
 */
enum Photometric: int
{
    use EnumFromIntStringNullable;

    case RGB   = 2;
    case YCBCR = 6;
}
