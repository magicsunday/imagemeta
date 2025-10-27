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
 * Enumerates EXIF scene types.
 */
enum SceneType: int
{
    use EnumFromIntStringNullable;

    case NOT_DEFINED                 = 0;
    case DIRECTLY_PHOTOGRAPHED_IMAGE = 1;

}
