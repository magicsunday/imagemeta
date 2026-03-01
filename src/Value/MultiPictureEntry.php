<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\MpImageDataFormat;
use MagicSunday\ImageMeta\Value\Enum\MpImageType;

/**
 * Represents a single MPF entry exposed to consumers of the curated metadata.
 */
final readonly class MultiPictureEntry
{
    /**
     * Creates a multi-picture format entry value object.
     *
     * @param int                    $attributes            Attribute bit field as defined by MPF.
     * @param int                    $imageSize             Size of the image data in bytes.
     * @param int                    $dataOffset            Offset to the image data from the file start.
     * @param int                    $dependentImage1       Index of the first dependent image entry.
     * @param int                    $dependentImage2       Index of the second dependent image entry.
     * @param bool                   $isDependentParent     Whether this image is a dependent parent.
     * @param bool                   $isDependentChild      Whether this image is a dependent child.
     * @param bool                   $isRepresentativeImage Whether this image is the representative image.
     * @param MpImageType|null       $imageType             MP type code decoded from the bitfield.
     * @param MpImageDataFormat|null $imageDataFormat       Image data format decoded from the bitfield.
     */
    public function __construct(
        public int $attributes,
        public int $imageSize,
        public int $dataOffset,
        public int $dependentImage1,
        public int $dependentImage2,
        public bool $isDependentParent,
        public bool $isDependentChild,
        public bool $isRepresentativeImage,
        public ?MpImageType $imageType,
        public ?MpImageDataFormat $imageDataFormat,
    ) {
    }
}
