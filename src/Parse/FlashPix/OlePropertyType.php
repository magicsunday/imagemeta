<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\FlashPix;

/**
 * OLE property set value type indicators (VT_* constants).
 *
 * Defined in the Microsoft OLE Property Set specification and used by the
 * FlashPix structured storage format (FPX Appendix A.2).
 */
enum OlePropertyType: int
{
    case Short       = 0x0002;
    case Long        = 0x0003;
    case Float       = 0x0004;
    case Double      = 0x0005;
    case Boolean     = 0x000B;
    case Lpstr       = 0x001E;
    case Lpwstr      = 0x001F;
    case Filetime    = 0x0040;
    case Blob        = 0x0041;
    case ClsId       = 0x0048;
    case VectorShort = 0x1002;
    case VectorLpstr = 0x101E;
}
