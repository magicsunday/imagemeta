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
 * Result of resolving a single ISO BMFF item payload.
 *
 * Replaces the by-reference `$unresolvedItems` parameter pattern in
 * {@see \MagicSunday\ImageMeta\Parse\IsoBmff\ItemPayloadResolver::resolveItemData()}.
 */
final readonly class IsoBmffItemResolveResult
{
    /**
     * @param string|null                 $data            Resolved payload data, or null if unresolvable.
     * @param list<IsoBmffUnresolvedItem> $unresolvedItems Items that could not be resolved.
     */
    public function __construct(
        public ?string $data,
        public array $unresolvedItems,
    ) {
    }
}
