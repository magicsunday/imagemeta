<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;

/**
 * TIFF image structure: sample layout, compression, and photometric interpretation.
 */
final readonly class TiffStructure
{
    /**
     * @param int|null                 $samplesPerPixel Number of samples per pixel.
     * @param int|null                 $bitsPerSample   Bits per sample reported for the image.
     * @param Compression|null         $compression     Compression method used for pixel data.
     * @param Photometric|null         $photometric     Photometric interpretation of the samples.
     * @param PlanarConfiguration|null $planar          Planar configuration for multi-sample data.
     */
    public function __construct(
        public ?int $samplesPerPixel = null,
        public ?int $bitsPerSample = null,
        public ?Compression $compression = null,
        public ?Photometric $photometric = null,
        public ?PlanarConfiguration $planar = null,
    ) {
    }
}
