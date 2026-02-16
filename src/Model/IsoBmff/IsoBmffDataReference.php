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
 *
 * ISO/IEC 14496-12:2015 §8.7.2.2 defines `DataEntryUrnBox ('urn ')` with
 * distinct `name` and optional `location` fields that are preserved here.
 */
final readonly class IsoBmffDataReference
{
    /**
     * @param int         $index         One-based data reference index.
     * @param string      $type          Four-character reference type.
     * @param string|null $uri           Legacy flattened URI representation.
     * @param bool        $selfContained Whether the reference is flagged as self-contained.
     * @param string|null $urlLocation   URL value for `url ` entries.
     * @param string|null $urnName       URN `name` value for `urn ` entries.
     * @param string|null $urnLocation   URN `location` value for `urn ` entries.
     */
    public function __construct(
        public int $index,
        public string $type,
        public ?string $uri,
        public bool $selfContained,
        public ?string $urlLocation = null,
        public ?string $urnName = null,
        public ?string $urnLocation = null,
    ) {
    }
}
