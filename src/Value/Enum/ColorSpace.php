<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Represents the colour space encodings described for the ColorSpace tag.
 *
 * EXIF 3.0 §4.6.6.2.1 (ColorSpace)
 */
enum ColorSpace: int
{
    use EnumFromIntStringNullable;

    case SRGB         = 1;
    case UNCALIBRATED = 65535;
}
