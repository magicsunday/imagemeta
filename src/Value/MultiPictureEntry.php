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
        public int $attributes,
        public int $imageSize,
        public int $dataOffset,
        public int $dependentImage1,
        public int $dependentImage2,
    ) {
    }

    /**
     * Returns the attribute bit field as defined by MPF.
     */
    public function attributes(): int
    {
        return $this->attributes;
    }

    /**
     * Returns the size of the image data in bytes.
     */
    public function imageSize(): int
    {
        return $this->imageSize;
    }

    /**
     * Returns the offset to the image data from the file start.
     */
    public function dataOffset(): int
    {
        return $this->dataOffset;
    }

    /**
     * Returns the index of the first dependent image entry.
     */
    public function dependentImage1(): int
    {
        return $this->dependentImage1;
    }

    /**
     * Returns the index of the second dependent image entry.
     */
    public function dependentImage2(): int
    {
        return $this->dependentImage2;
    }
}
