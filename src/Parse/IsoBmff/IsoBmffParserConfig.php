<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;

/**
 * Immutable configuration values for ISO BMFF parser guard limits.
 */
final readonly class IsoBmffParserConfig
{
    /**
     * @param int $maxItemPayloadSize     Maximum cumulative payload size in bytes when assembling item extents.
     * @param int $maxNestedMetadataDepth Maximum supported nesting depth for data-type 28 metadata payloads.
     */
    public function __construct(
        public int $maxItemPayloadSize = 8_388_608,
        public int $maxNestedMetadataDepth = 1,
    ) {
        if ($this->maxItemPayloadSize < 1) {
            throw new ParseError('maxItemPayloadSize must be greater than zero.', 1858);
        }

        if ($this->maxNestedMetadataDepth < 0) {
            throw new ParseError('maxNestedMetadataDepth must not be negative.', 1859);
        }
    }
}
