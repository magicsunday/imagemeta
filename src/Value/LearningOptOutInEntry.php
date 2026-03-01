<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\LearningIntention;
use MagicSunday\ImageMeta\Value\Enum\LearningUsage;

/**
 * A single (usage, intention) pair from the LearningOptOutIn tag.
 */
final readonly class LearningOptOutInEntry
{
    /**
     * Creates a learning opt-out/opt-in entry.
     *
     * @param LearningUsage     $usage     The AI/ML usage category.
     * @param LearningIntention $intention The copyright holder's intention for this usage.
     */
    public function __construct(
        public LearningUsage $usage,
        public LearningIntention $intention,
    ) {
    }
}
