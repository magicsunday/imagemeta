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

/**
 * Describes the availability of embedded thumbnails (IFD1).
 */
final readonly class Thumbnail
{
    /**
     * Creates a thumbnail metadata value object.
     *
     * @param bool             $hasThumbnail             Whether an embedded thumbnail exists.
     * @param int|null         $thumbnailOffset          Byte offset to the legacy thumbnail (IFD1) payload.
     * @param int|null         $thumbnailLength          Byte length of the legacy thumbnail payload.
     * @param Compression|null $thumbnailCompression     Compression applied to the legacy thumbnail.
     * @param int|null         $thumbnailTileWidth       Tile width of the legacy thumbnail.
     * @param int|null         $thumbnailTileLength      Tile length of the legacy thumbnail.
     * @param list<int>|null   $thumbnailTileOffsets     Tile offsets describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailTileByteCounts  Tile byte counts describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailStripOffsets    Strip offsets describing the legacy thumbnail payload.
     * @param list<int>|null   $thumbnailStripByteCounts Strip byte counts describing the legacy thumbnail payload.
     */
    public function __construct(
        public bool $hasThumbnail,
        public ?int $thumbnailOffset = null,
        public ?int $thumbnailLength = null,
        public ?Compression $thumbnailCompression = null,
        public ?int $thumbnailTileWidth = null,
        public ?int $thumbnailTileLength = null,
        public ?array $thumbnailTileOffsets = null,
        public ?array $thumbnailTileByteCounts = null,
        public ?array $thumbnailStripOffsets = null,
        public ?array $thumbnailStripByteCounts = null,
    ) {
    }
}
