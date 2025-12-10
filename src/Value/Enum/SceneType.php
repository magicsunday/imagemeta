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
 * Enumerates the scene type encodings recorded by the SceneType tag in EXIF
 * 3.0 §4.6.6.7.33 (SceneType), preserving the EXIF 2.32 §4.6.6.7.33 meaning
 * that directly photographed images use the value 1.
 */
enum SceneType: int
{
    use EnumFromIntStringNullable;

    case NOT_DEFINED                 = 0;
    case DIRECTLY_PHOTOGRAPHED_IMAGE = 1;
}
