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
 * Represents hierarchical and flat keyword annotations.
 */
final readonly class Keywords
{
    /**
     * @param list<string>      $flat         Flat keyword list.
     * @param list<string>|null $hierarchical Optional hierarchical keywords.
     */
    public function __construct(
        public array $flat,
        public ?array $hierarchical,
    ) {
    }
}
