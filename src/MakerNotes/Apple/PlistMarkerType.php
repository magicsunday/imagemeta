<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Binary plist object marker high-nibble values.
 */
enum PlistMarkerType: int
{
    case Simple     = 0x0;
    case Integer    = 0x1;
    case Real       = 0x2;
    case Date       = 0x3;
    case Data       = 0x4;
    case Ascii      = 0x5;
    case Unicode    = 0x6;
    case Utf8       = 0x7;
    case Uid        = 0x8;
    case Array      = 0xA;
    case Set        = 0xB;
    case Dictionary = 0xD;
}
