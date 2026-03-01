<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\IsoBmff;

use function array_key_exists;
use function array_keys;

/**
 * Holds ISO BMFF item references keyed by metadata context and source item id.
 *
 * @uses UnambiguousReferenceResolver<list<IsoBmffItemReference>>
 */
final readonly class IsoBmffItemReferenceMap
{
    /** @use UnambiguousReferenceResolver<list<IsoBmffItemReference>> */
    use UnambiguousReferenceResolver;

    /**
     * Legacy unambiguous lookup table keyed by source item id.
     *
     * If the same source item id exists in multiple contexts, it is omitted here
     * to avoid ambiguous cross-context lookup results.
     *
     * @var array<int, list<IsoBmffItemReference>>
     */
    public array $references;

    /**
     * @param array<int, array<int, list<IsoBmffItemReference>>> $referencesByContext
     */
    public function __construct(public array $referencesByContext)
    {
        $this->references = $this->buildUnambiguousReferenceIndex($referencesByContext);
    }

    /**
     * Returns all source item identifiers with registered references.
     *
     * @return list<int>
     */
    public function fromItemIds(): array
    {
        $fromItemIds = [];

        foreach ($this->referencesByContext as $contextReferences) {
            foreach ($contextReferences as $fromItemId => $_references) {
                if (!array_key_exists($fromItemId, $fromItemIds)) {
                    $fromItemIds[$fromItemId] = true;
                }
            }
        }

        return array_keys($fromItemIds);
    }

    /**
     * Returns all metadata context offsets that expose item references.
     *
     * @return list<int>
     */
    public function contextOffsets(): array
    {
        return array_keys($this->referencesByContext);
    }

    /**
     * Returns references from the given source item id in the provided metadata context.
     *
     * @return list<IsoBmffItemReference>
     */
    public function referencesForContext(int $contextOffset, int $fromItemId): array
    {
        return $this->referencesByContext[$contextOffset][$fromItemId] ?? [];
    }

    /**
     * Returns references that originate from the given item id.
     *
     * For ambiguous item ids that occur in multiple metadata contexts, returns an empty list.
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
        return $this->referencesByContext === [];
    }
}
