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
 * Enumerates chroma positioning relative to luma samples in YCbCr images.
 */
enum YCbCrPositioning: int
{
    use EnumFromIntStringNullable;

    case CENTERED = 1;
    case CO_SITED = 2;

}
