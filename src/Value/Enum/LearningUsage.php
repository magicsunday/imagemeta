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
 * Enumerates usage categories for the LearningOptOutIn tag;
 * EXIF 3.1 §4.6.5.4.
 */
enum LearningUsage: int
{
    use EnumFromIntStringNullable;

    /** All / Individual usage is not specified. */
    case All                   = 0;

    /** Non-Generative AI/ML Training. */
    case NonGenerativeTraining = 1;

    /** Generative AI/ML Training. */
    case GenerativeTraining    = 2;

    /** Data Mining. */
    case DataMining            = 3;

    /** Input to Foundation Model (purpose of inferring a result). */
    case FoundationModelInput  = 4;
}
