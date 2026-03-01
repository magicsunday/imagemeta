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
 * Holds ISO BMFF data references keyed by metadata context and index.
 *
 * @uses UnambiguousReferenceResolver<IsoBmffDataReference>
 */
final readonly class IsoBmffDataReferenceMap
{
    /** @use UnambiguousReferenceResolver<IsoBmffDataReference> */
    use UnambiguousReferenceResolver;

    /**
     * Legacy unambiguous lookup table keyed by index.
     *
     * If the same index exists in multiple contexts, it is omitted here to avoid
     * ambiguous cross-context lookup results.
     *
     * @var array<int, IsoBmffDataReference>
     */
    public array $references;

    /**
     * @param array<int, array<int, IsoBmffDataReference>> $referencesByContext
     */
    public function __construct(public array $referencesByContext)
    {
        $this->references = $this->buildUnambiguousReferenceIndex($referencesByContext);
    }

    /**
     * Returns all data reference indexes.
     *
     * @return list<int>
     */
    public function indexes(): array
    {
        $indexes = [];

        foreach ($this->referencesByContext as $contextReferences) {
            foreach ($contextReferences as $index => $_reference) {
                if (!array_key_exists($index, $indexes)) {
                    $indexes[$index] = true;
                }
            }
        }

        return array_keys($indexes);
    }

    /**
     * Returns all metadata context offsets that expose data references.
     *
     * @return list<int>
     */
    public function contextOffsets(): array
    {
        return array_keys($this->referencesByContext);
    }

    /**
     * Returns data references declared in the given metadata context.
     *
     * @return array<int, IsoBmffDataReference>
     */
    public function referencesForContext(int $contextOffset): array
    {
        return $this->referencesByContext[$contextOffset] ?? [];
    }

    /**
     * Returns the data reference for the provided metadata context and index.
     */
    public function referenceForContextIndex(int $contextOffset, int $index): ?IsoBmffDataReference
    {
        return $this->referencesByContext[$contextOffset][$index] ?? null;
    }

    /**
     * Returns the unambiguous data reference for the provided index.
     *
     * If multiple metadata contexts declare the same index, the lookup returns null.
     */
    public function referenceForIndex(int $index): ?IsoBmffDataReference
    {
        return $this->references[$index] ?? null;
    }

    /**
     * Reports whether any data references are available.
     */
    public function isEmpty(): bool
    {
        return $this->referencesByContext === [];
    }
}
