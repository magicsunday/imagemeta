<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents a single MPF entry exposed to consumers of the curated metadata.
 */
final readonly class MultiPictureEntry
{
    public function __construct(
        public int $attributes,
        public int $imageSize,
        public int $dataOffset,
        public int $dependentImage1,
        public int $dependentImage2,
    ) {
    }
}
