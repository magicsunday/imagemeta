<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Enumerates MP type codes from the Individual Image Attribute bitfield;
 * CIPA DC-007-2025, §5.2.3.3.1, Table 4.
 */
enum MpImageType: int
{
    case Undefined                 = 0x000000;
    case LargeThumbnailVga         = 0x010001;
    case LargeThumbnailFullHd      = 0x010002;
    case LargeThumbnailQfhd        = 0x010003;
    case LargeThumbnail8k          = 0x010004;
    case LargeThumbnail16k         = 0x010005;
    case MultiFramePanorama        = 0x020001;
    case MultiFrameDisparity       = 0x020002;
    case MultiFrameMultiAngle      = 0x020003;
    case BaselinePrimaryImage      = 0x030000;
    case OriginalPreservationImage = 0x040000;
    case GainMapImage              = 0x050000;
}
