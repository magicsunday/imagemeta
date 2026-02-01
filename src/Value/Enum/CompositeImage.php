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
 * Enumerates the composite image classifications defined for the CompositeImage
 * tag.
 *
 * EXIF 3.0 §4.6.6.7.47 defines 0 = Unknown, 1 = Non-composite image, 2 =
 * General composite image, 3 = Composite image captured when shooting. EXIF
 * 2.32 §4.6.6.7.47 retains the same numeric encodings and reserved range.
 */
enum CompositeImage: int
{
    use EnumFromIntStringNullable;

    case UNKNOWN                 = 0;
    case NOT_COMPOSITE           = 1;
    case GENERAL_COMPOSITE       = 2;
    case CAPTURED_WHILE_SHOOTING = 3;
}
