<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use MagicSunday\ImageMeta\Value\Enum\Compression;

/**
 * Read-only access contract for IFD1 thumbnail metadata.
 *
 * EXIF 3.0 §4.5.5 and TIFF 6.0 §8 cover thumbnail-oriented tags in IFD1.
 */
interface ExifIfd1Data
{
    public function hasThumbnail(): bool;

    public function thumbnailCompression(): ?Compression;

    public function thumbnailJpegInterchangeFormat(): ?int;

    public function thumbnailJpegInterchangeFormatLength(): ?int;

    public function thumbnailTileWidth(): ?int;

    public function thumbnailTileLength(): ?int;

    /**
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array;

    /**
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array;

    /**
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array;

    /**
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array;
}
