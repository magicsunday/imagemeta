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
 * Enumerates TIFF planar configuration options.
 */
enum PlanarConfiguration: int
{
    use EnumFromIntStringNullable;

    case CHUNKY = 1;
    case PLANAR = 2;

}
