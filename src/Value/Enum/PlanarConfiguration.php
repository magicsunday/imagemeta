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
 * Enumerates TIFF planar configuration options described in
 * EXIF 2.32 §4.6.2 and EXIF 3.0 §4.6.2 (image data structure),
 * reflecting the TIFF 6.0 §8 PlanarConfiguration field.
 */
enum PlanarConfiguration: int
{
    use EnumFromIntStringNullable;

    case CHUNKY = 1;
    case PLANAR = 2;

}
