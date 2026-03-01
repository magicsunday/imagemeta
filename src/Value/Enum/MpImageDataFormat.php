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
 * Enumerates image data format codes from the Individual Image Attribute bitfield;
 * CIPA DC-007-2025, §5.2.3.3, Figure 8.
 */
enum MpImageDataFormat: int
{
    case Jpeg = 0;
}
