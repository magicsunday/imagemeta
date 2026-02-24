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
 * Exposure program encodings for EXIF tag 0x8822 ExposureProgram.
 *
 * EXIF 3.0 §4.6.6.7.3 defines values 0–8; all other payloads are reserved and mapped to null.
 */
enum ExposureProgram: int
{
    use EnumFromIntStringNullable;

    case NotDefined       = 0;
    case Manual           = 1;
    case Normal           = 2;
    case AperturePriority = 3;
    case ShutterPriority  = 4;
    case CreativeProgram  = 5;
    case ActionProgram    = 6;
    case PortraitMode     = 7;
    case LandscapeMode    = 8;
}
