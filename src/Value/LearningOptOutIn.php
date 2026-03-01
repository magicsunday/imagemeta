<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents the copyright holder's AI/ML training opt-out/opt-in intentions
 * parsed from the LearningOptOutIn EXIF tag (EXIF 3.1 §4.6.5.4).
 */
final readonly class LearningOptOutIn
{
    /**
     * Creates a learning opt-out/opt-in metadata value object.
     *
     * @param list<LearningOptOutInEntry> $entries Sequence of (usage, intention) pairs.
     */
    public function __construct(
        public array $entries,
    ) {
    }
}
