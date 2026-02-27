<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

/**
 * QuickTime `data` atom well-known type codes (QuickTime File Format 2012, Table 3-5).
 */
enum QuickTimeDataType: int
{
    case Utf8           = 0x01;
    case Utf16          = 0x02;
    case ShiftJis       = 0x03;
    case Utf8Sort       = 0x04;
    case Utf16Sort      = 0x05;
    case MacRoman       = 0x07;
    case JpegWrapper    = 0x0D;
    case PngWrapper     = 0x0E;
    case SignedInt      = 0x15;
    case UnsignedInt    = 0x16;
    case Float32        = 0x17;
    case Float64        = 0x18;
    case BmpWrapper     = 0x1B;
    case NestedMetadata = 0x1C;
}
