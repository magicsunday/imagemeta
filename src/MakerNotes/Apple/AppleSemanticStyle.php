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
 * Semantic style adjustments extracted from Apple maker notes.
 */
final readonly class AppleSemanticStyle
{
    /**
     * @param string|null $preset Selected semantic style preset name.
     * @param float|null  $warmth Semantic style warmth adjustment.
     * @param float|null  $tone   Semantic style tone adjustment.
     */
    public function __construct(
        public ?string $preset,
        public ?float $warmth,
        public ?float $tone,
    ) {
    }
}
