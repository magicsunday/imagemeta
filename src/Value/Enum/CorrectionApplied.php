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
 * Enumerates correction processing states shared by DistortionCorrection,
 * ChromaticAberrationCorrection and ShadingCorrection tags;
 * EXIF 3.1 §4.6.6.7.49–51.
 */
enum CorrectionApplied: int
{
    use EnumFromIntStringNullable;

    case NotApplied = 0;
    case Applied    = 1;
}
