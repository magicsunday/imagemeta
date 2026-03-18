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
 * Enumerates the factory default comparison for image processing (low byte of
 * DevelopmentType); EXIF 3.1 §4.6.6.7.47.
 */
enum DevelopmentDefault: int
{
    use EnumFromIntStringNullable;

    /** Not different (factory default development of capture device). */
    case FactoryDefault = 0x01;

    /** Different from factory default. */
    case Different      = 0x02;

    /** Unknown whether factory default was used. */
    case Unknown        = 0x04;
}
