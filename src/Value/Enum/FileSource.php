<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the image acquisition sources recorded by the FileSource tag in
 * EXIF 3.0 §4.6.6.7.32 (FileSource).
 */
enum FileSource: int
{
    use EnumFromIntStringNullable;

    case Other               = 0;
    case TransparencyScanner = 1;
    case ReflectionScanner   = 2;
    case DigitalCamera       = 3;
}
