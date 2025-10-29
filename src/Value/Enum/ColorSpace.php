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
 * Represents the image colour space information defined in
 * EXIF 2.32 §4.6.3 and EXIF 3.0 §4.6.3 (shooting conditions).
 */
enum ColorSpace: int
{
    use EnumFromIntStringNullable;

    case SRGB         = 1;
    case ADOBE_RGB    = 2;
    case UNCALIBRATED = 65535;

}
