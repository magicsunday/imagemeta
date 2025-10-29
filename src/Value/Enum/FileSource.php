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
 * Enumerates the image acquisition sources recorded by the FileSource tag in
 * EXIF 3.0 §4.6.3 (shooting conditions), preserving the list from EXIF 2.32
 * §4.6.3.
 */
enum FileSource: int
{
    use EnumFromIntStringNullable;

    case OTHER                = 0;
    case TRANSPARENCY_SCANNER = 1;
    case REFLECTION_SCANNER   = 2;
    case DIGITAL_CAMERA       = 3;
}
