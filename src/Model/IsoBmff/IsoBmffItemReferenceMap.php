<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\IsoBmff;

use function array_keys;

/**
 * Holds ISO BMFF item references keyed by source item id.
 */
final readonly class IsoBmffItemReferenceMap
{
    /**
     * @param array<int, list<IsoBmffItemReference>> $references
     */
    public function __construct(public array $references)
    {
    }

    /**
     * Returns all source item identifiers with registered references.
     *
     * @return list<int>
     */
    public function fromItemIds(): array
    {
        return array_keys($this->references);
    }

    /**
     * Returns references that originate from the given item id.
     *
     * @return list<IsoBmffItemReference>
     */
    public function referencesFor(int $fromItemId): array
    {
        return $this->references[$fromItemId] ?? [];
    }

    /**
     * Reports whether any item references are available.
     */
    public function isEmpty(): bool
    {
        return $this->references === [];
    }
}
