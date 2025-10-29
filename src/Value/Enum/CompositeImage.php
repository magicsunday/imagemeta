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
 * Enumerates the composite image classifications defined for the CompositeImage
 * tag in EXIF 3.0 §4.6.3 (shooting conditions), continuing the set introduced
 * in EXIF 2.32 §4.6.3.
 */
enum CompositeImage: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN                 = 0;
    case NOT_COMPOSITE           = 1;
    case GENERAL_COMPOSITE       = 2;
    case CAPTURED_WHILE_SHOOTING = 3;
}
