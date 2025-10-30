<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;

/**
 * Describes the availability of embedded previews or thumbnails.
 */
final readonly class Preview
{
    /**
     * @param bool|null        $hasThumbnail              Whether an embedded thumbnail exists.
     * @param bool|null        $hasPreview                Whether an embedded preview image exists.
     * @param int|null         $previewWidth              Width of the preview image in pixels.
     * @param int|null         $previewHeight             Height of the preview image in pixels.
     * @param ColorSpace|null  $previewColorSpace         Colour space of the preview image.
     * @param int|null         $previewBitDepth           Bit depth of the preview image.
     * @param Compression|null $previewCompression        Compression applied to the preview payload.
     * @param float|null       $previewScale              Scale factor applied to the preview relative to the main image.
     * @param string|null      $previewEncoding           Encoding name for the preview image payload.
     * @param string|null      $previewMimeType           MIME type of the preview image.
     * @param int|null         $previewOffset             Byte offset to the preview image inside the file.
     * @param int|null         $previewLength             Byte length of the preview image data.
     * @param int|null         $thumbnailOffset           Byte offset to the legacy thumbnail (IFD1) payload.
     * @param int|null         $thumbnailLength           Byte length of the legacy thumbnail payload.
     * @param Compression|null $thumbnailCompression      Compression applied to the legacy thumbnail.
     * @param list<int>|null   $thumbnailStripOffsets     Strip offsets describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailStripByteCounts  Strip byte counts describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailTileOffsets      Tile offsets describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailTileByteCounts   Tile byte counts describing the legacy thumbnail payload.
     * @param list<int>|null   $previewStripOffsets       Strip offsets describing the EXIF 3.0 preview payload.
     * @param list<int>|null   $previewStripByteCounts    Strip byte counts describing the EXIF 3.0 preview payload.
     * @param list<int>|null   $previewTileOffsets        Tile offsets describing the EXIF 3.0 preview payload.
     * @param list<int>|null   $previewTileByteCounts     Tile byte counts describing the EXIF 3.0 preview payload.
     */
    public function __construct(
        public ?bool $hasThumbnail,
        public ?bool $hasPreview,
        public ?int $previewWidth,
        public ?int $previewHeight,
        public ?ColorSpace $previewColorSpace,
        public ?int $previewBitDepth,
        public ?Compression $previewCompression,
        public ?float $previewScale,
        public ?string $previewEncoding,
        public ?string $previewMimeType,
        public ?int $previewOffset,
        public ?int $previewLength,
        public ?int $thumbnailOffset = null,
        public ?int $thumbnailLength = null,
        public ?Compression $thumbnailCompression = null,
        public ?array $thumbnailStripOffsets = null,
        public ?array $thumbnailStripByteCounts = null,
        public ?array $thumbnailTileOffsets = null,
        public ?array $thumbnailTileByteCounts = null,
        public ?array $previewStripOffsets = null,
        public ?array $previewStripByteCounts = null,
        public ?array $previewTileOffsets = null,
        public ?array $previewTileByteCounts = null,
    ) {
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
