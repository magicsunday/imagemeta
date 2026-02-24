<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\IsoBmff;

/**
 * Result of resolving a queue of ISO BMFF item payloads.
 *
 * Replaces the by-reference `$unresolvedItems` parameter pattern in
 * {@see \MagicSunday\ImageMeta\Parse\IsoBmff\ItemLocationResolver::resolveQueuedItems()}.
 */
final readonly class IsoBmffQueuedResolveResult
{
    /**
     * @param list<string>                $resolved        Successfully resolved item payloads.
     * @param list<IsoBmffUnresolvedItem> $unresolvedItems Items that could not be resolved.
     */
    public function __construct(
        public array $resolved,
        public array $unresolvedItems,
    ) {
    }
}
