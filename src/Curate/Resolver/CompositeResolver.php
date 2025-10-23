<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use Closure;

/**
 * Selects the first non-null value from a list of resolver callables.
 */
final readonly class CompositeResolver
{
    /**
     * @param list<Closure():T|T> $candidates
     *
     * @return T|null
     *
     * @template T
     */
    public static function first(array $candidates)
    {
        foreach ($candidates as $candidate) {
            $value = $candidate instanceof Closure ? $candidate() : $candidate;

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
