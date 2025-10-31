<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;

interface PreviewInterface
{
    public function hasThumbnail(): ?bool;

    public function hasPreview(): ?bool;

    public function previewWidth(): ?int;

    public function previewHeight(): ?int;

    public function previewColorSpace(): ?ColorSpace;

    public function previewBitDepth(): ?int;

    public function previewCompression(): ?Compression;

    public function previewScale(): ?float;

    public function previewEncoding(): ?string;

    public function previewMimeType(): ?string;

    public function previewOffset(): ?int;

    public function previewLength(): ?int;

    public function thumbnailOffset(): ?int;

    public function thumbnailLength(): ?int;

    public function thumbnailCompression(): ?Compression;

    /**
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array;

    /**
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array;

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
    public function previewStripOffsets(): ?array;

    /**
     * @return list<int>|null
     */
    public function previewStripByteCounts(): ?array;

    /**
     * @return list<int>|null
     */
    public function previewTileOffsets(): ?array;

    /**
     * @return list<int>|null
     */
    public function previewTileByteCounts(): ?array;
}
