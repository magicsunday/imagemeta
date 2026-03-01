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
 * Enumerates the copyright holder's intention for AI/ML training usage;
 * EXIF 3.1 §4.6.5.4.
 */
enum LearningIntention: int
{
    use EnumFromIntStringNullable;

    /** Opt-out of the specified usage. */
    case OptOut = 0;

    /** Opt-in to the specified usage. */
    case OptIn = 1;

    /** Intention is unspecified. */
    case Unspecified = 2;
}
