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
 * @deprecated Internal bridging wrapper scheduled for removal after Milestone M1.
 *             Use MagicSunday\ImageMeta\Curate\Structured\MediaMetadata for preview data.
 */
final readonly class Preview
{
    public ?bool $hasThumbnail;

    public ?bool $hasPreview;

    public ?int $previewWidth;

    public ?int $previewHeight;

    public ?ColorSpace $previewColorSpace;

    public ?int $previewBitDepth;

    public ?Compression $previewCompression;

    public ?float $previewScale;

    public ?string $previewEncoding;

    public ?string $previewMimeType;

    public ?int $previewOffset;

    public ?int $previewLength;

    public ?int $thumbnailOffset;

    public ?int $thumbnailLength;

    public ?Compression $thumbnailCompression;

    /**
     * @var list<int>|null
     */
    public ?array $thumbnailStripOffsets;

    /**
     * @var list<int>|null
     */
    public ?array $thumbnailStripByteCounts;

    /**
     * @var list<int>|null
     */
    public ?array $thumbnailTileOffsets;

    /**
     * @var list<int>|null
     */
    public ?array $thumbnailTileByteCounts;

    /**
     * @var list<int>|null
     */
    public ?array $previewStripOffsets;

    /**
     * @var list<int>|null
     */
    public ?array $previewStripByteCounts;

    /**
     * @var list<int>|null
     */
    public ?array $previewTileOffsets;

    /**
     * @var list<int>|null
     */
    public ?array $previewTileByteCounts;

    /**
     * @param PreviewValue $preview Raw preview value object describing embedded thumbnails and previews from EXIF.
     */
    public function __construct(PreviewValue $preview)
    {
        $this->hasThumbnail             = $preview->hasThumbnail;
        $this->hasPreview               = $preview->hasPreview;
        $this->previewWidth             = $preview->previewWidth;
        $this->previewHeight            = $preview->previewHeight;
        $this->previewColorSpace        = $preview->previewColorSpace;
        $this->previewBitDepth          = $preview->previewBitDepth;
        $this->previewCompression       = $preview->previewCompression;
        $this->previewScale             = $preview->previewScale;
        $this->previewEncoding          = $preview->previewEncoding;
        $this->previewMimeType          = $preview->previewMimeType;
        $this->previewOffset            = $preview->previewOffset;
        $this->previewLength            = $preview->previewLength;
        $this->thumbnailOffset          = $preview->thumbnailOffset;
        $this->thumbnailLength          = $preview->thumbnailLength;
        $this->thumbnailCompression     = $preview->thumbnailCompression;
        $this->thumbnailStripOffsets    = $preview->thumbnailStripOffsets;
        $this->thumbnailStripByteCounts = $preview->thumbnailStripByteCounts;
        $this->thumbnailTileOffsets     = $preview->thumbnailTileOffsets;
        $this->thumbnailTileByteCounts  = $preview->thumbnailTileByteCounts;
        $this->previewStripOffsets      = $preview->previewStripOffsets;
        $this->previewStripByteCounts   = $preview->previewStripByteCounts;
        $this->previewTileOffsets       = $preview->previewTileOffsets;
        $this->previewTileByteCounts    = $preview->previewTileByteCounts;
    }

    public function hasThumbnail(): ?bool
    {
        return $this->hasThumbnail;
    }

    public function hasPreview(): ?bool
    {
        return $this->hasPreview;
    }

    public function previewWidth(): ?int
    {
        return $this->previewWidth;
    }

    public function previewHeight(): ?int
    {
        return $this->previewHeight;
    }

    public function previewColorSpace(): ?ColorSpace
    {
        return $this->previewColorSpace;
    }

    public function previewBitDepth(): ?int
    {
        return $this->previewBitDepth;
    }

    public function previewCompression(): ?Compression
    {
        return $this->previewCompression;
    }

    public function previewScale(): ?float
    {
        return $this->previewScale;
    }

    public function previewEncoding(): ?string
    {
        return $this->previewEncoding;
    }

    public function previewMimeType(): ?string
    {
        return $this->previewMimeType;
    }

    public function previewOffset(): ?int
    {
        return $this->previewOffset;
    }

    public function previewLength(): ?int
    {
        return $this->previewLength;
    }

    public function thumbnailOffset(): ?int
    {
        return $this->thumbnailOffset;
    }

    public function thumbnailLength(): ?int
    {
        return $this->thumbnailLength;
    }

    public function thumbnailCompression(): ?Compression
    {
        return $this->thumbnailCompression;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        return $this->thumbnailStripOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        return $this->thumbnailStripByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->thumbnailTileOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->thumbnailTileByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function previewStripOffsets(): ?array
    {
        return $this->previewStripOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function previewStripByteCounts(): ?array
    {
        return $this->previewStripByteCounts;
    }

    /**
     * @return list<int>|null
     */
    public function previewTileOffsets(): ?array
    {
        return $this->previewTileOffsets;
    }

    /**
     * @return list<int>|null
     */
    public function previewTileByteCounts(): ?array
    {
        return $this->previewTileByteCounts;
    }
}
