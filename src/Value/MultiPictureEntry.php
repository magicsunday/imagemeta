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
    /**
     * @param int $attributes      Attribute bit field as defined by MPF.
     * @param int $imageSize       Size of the image data in bytes.
     * @param int $dataOffset      Offset to the image data from the file start.
     * @param int $dependentImage1 Index of the first dependent image entry.
     * @param int $dependentImage2 Index of the second dependent image entry.
     */
    public function __construct(
        public readonly int $attributes,
        public readonly int $imageSize,
        public readonly int $dataOffset,
        public readonly int $dependentImage1,
        public readonly int $dependentImage2,
    ) {
    }
}
