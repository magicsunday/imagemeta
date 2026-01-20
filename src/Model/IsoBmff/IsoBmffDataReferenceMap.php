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
 * Holds ISO BMFF data references keyed by index.
 */
final readonly class IsoBmffDataReferenceMap
{
    /**
     * @param array<int, IsoBmffDataReference> $references
     */
    public function __construct(public array $references)
    {
    }

    /**
     * Returns all data reference indexes.
     *
     * @return list<int>
     */
    public function indexes(): array
    {
        return array_keys($this->references);
    }

    /**
     * Returns the data reference for the provided index.
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
        return $this->references === [];
    }
}
