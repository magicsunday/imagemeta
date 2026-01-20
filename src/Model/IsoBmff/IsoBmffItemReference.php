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
 * Represents a single ISO BMFF item reference entry.
 */
final readonly class IsoBmffItemReference
{
    /**
     * @param string $relation Four-character item reference type.
     * @param int    $toItemId Target item identifier.
     */
    public function __construct(
        public string $relation,
        public int $toItemId,
    ) {
    }
}
