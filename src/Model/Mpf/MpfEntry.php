<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Mpf;

/**
 * Represents a single entry within the MP Index IFD describing one image in the set.
 */
final readonly class MpfEntry
{
    /**
     * @param int $attributes      MPF image attributes bitfield.
     * @param int $imageSize       Size of the image data in bytes.
     * @param int $dataOffset      Offset to the image data within the file.
     * @param int $dependentImage1 Dependent image 1 index.
     * @param int $dependentImage2 Dependent image 2 index.
     */
    public function __construct(
        public int $attributes,
        public int $imageSize,
        public int $dataOffset,
        public int $dependentImage1,
        public int $dependentImage2,
    ) {
    }
}
