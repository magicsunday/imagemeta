<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Preview as PreviewValue;

/**
 * Indicates the availability of previews and thumbnails from EXIF.
 *
 * @deprecated since milestone M4. This transitional wrapper will be removed in the
 *             following release. Consume the underlying Value objects directly instead.
 */
final readonly class Preview
{
    public function __construct(private PreviewValue $preview)
    {
    }

    public function value(): PreviewValue
    {
        return $this->preview;
    }

    public function hasThumbnail(): ?bool
    {
        return $this->preview->hasThumbnail;
    }

    public function hasPreview(): ?bool
    {
        return $this->preview->hasPreview;
    }

    public function previewWidth(): ?int
    {
        return $this->preview->previewWidth;
    }

    public function previewHeight(): ?int
    {
        return $this->preview->previewHeight;
    }

    public function previewColorSpace(): ?ColorSpace
    {
        return $this->preview->previewColorSpace;
    }

    public function previewBitDepth(): ?int
    {
        return $this->preview->previewBitDepth;
    }

    public function previewCompression(): ?Compression
    {
        return $this->preview->previewCompression;
    }

    public function previewScale(): ?float
    {
        return $this->preview->previewScale;
    }

    public function previewEncoding(): ?string
    {
        return $this->preview->previewEncoding;
    }

    public function previewMimeType(): ?string
    {
        return $this->preview->previewMimeType;
    }

    public function previewOffset(): ?int
    {
        return $this->preview->previewOffset;
    }

    public function previewLength(): ?int
    {
        return $this->preview->previewLength;
    }

    public function thumbnailOffset(): ?int
    {
        return $this->preview->thumbnailOffset;
    }

    public function thumbnailLength(): ?int
    {
        return $this->preview->thumbnailLength;
    }

    public function thumbnailCompression(): ?Compression
    {
        return $this->preview->thumbnailCompression;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        return $this->preview->thumbnailStripOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        return $this->preview->thumbnailStripByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->preview->thumbnailTileOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->preview->thumbnailTileByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function previewStripOffsets(): ?array
    {
        return $this->preview->previewStripOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function previewStripByteCounts(): ?array
    {
        return $this->preview->previewStripByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function previewTileOffsets(): ?array
    {
        return $this->preview->previewTileOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function previewTileByteCounts(): ?array
    {
        return $this->preview->previewTileByteCounts;
    }
}
