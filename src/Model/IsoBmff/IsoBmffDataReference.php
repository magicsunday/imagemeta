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
 * Represents a single ISO BMFF data reference entry.
 */
final readonly class IsoBmffDataReference
{
    /**
     * @param int         $index         One-based data reference index.
     * @param string      $type          Four-character reference type.
     * @param string|null $uri           Reference URI when provided.
     * @param bool        $selfContained Whether the reference is flagged as self-contained.
     */
    public function __construct(
        public int $index,
        public string $type,
        public ?string $uri,
        public bool $selfContained,
    ) {
    }
}
