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

/**
 * Resolves references from multiple parsing contexts, keeping only those
 * with a unique key across all contexts.
 *
 * @template TValue
 */
trait UnambiguousReferenceResolver
{
    /**
     * Flattens references into a map where each key is unique across contexts.
     *
     * If the same key exists in multiple contexts, it is omitted to avoid
     * ambiguous cross-context lookup results.
     *
     * @param array<int, array<int, TValue>> $referencesByContext
     *
     * @return array<int, TValue>
     */
    private function buildUnambiguousReferenceIndex(array $referencesByContext): array
    {
        $resolved  = [];
        $ambiguous = [];

        foreach ($referencesByContext as $contextReferences) {
            foreach ($contextReferences as $key => $value) {
                if (array_key_exists($key, $ambiguous)) {
                    continue;
                }

                if (array_key_exists($key, $resolved)) {
                    unset($resolved[$key]);
                    $ambiguous[$key] = true;

                    continue;
                }

                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
