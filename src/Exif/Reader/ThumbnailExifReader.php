<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;

use function in_array;

/**
 * Reads thumbnail-related metadata from IFD1.
 *
 * EXIF 3.0 §4.6.5.1.6 defines how thumbnails are stored in IFD1 of the TIFF structure.
 */
final readonly class ThumbnailExifReader
{
    /**
     * @param IfdValueReader $reader Value reader for IFD tag extraction.
     * @param Ifd|null       $ifd1   Optional primary thumbnail IFD.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ?Ifd $ifd1,
    ) {
    }

    /**
     * Indicates whether a JPEG thumbnail is referenced by the EXIF structure.
     *
     * EXIF 3.0 §4.6.5.1.6 describes the JPEG thumbnail tags and requires both
     * offset and length to be populated for a valid embedded thumbnail.
     * EXIF 3.0 §4.6.5.1.4 requires Compression value 6 (JPEG) for JPEG thumbnails.
     */
    public function hasThumbnail(): bool
    {
        $compression = $this->thumbnailCompression();
        $offset      = $this->thumbnailJpegInterchangeFormat();
        $length      = $this->thumbnailJpegInterchangeFormatLength();

        if ($compression !== Compression::JPEG) {
            return false;
        }

        if ($offset === null || $length === null) {
            return false;
        }

        return $length > 0;
    }

    /**
     * Returns the JPEG thumbnail offset from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.5.2.4 documents JPEGInterchangeFormat as the byte offset to embedded
     * JPEG thumbnails stored in IFD1 (the first IFD after IFD0).
     */
    public function thumbnailJpegInterchangeFormat(): ?int
    {
        return $this->reader->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG thumbnail byte length from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 (Table 3) defines JPEGInterchangeFormatLength as the size in bytes
     * of the JPEG thumbnail stream in IFD1.
     */
    public function thumbnailJpegInterchangeFormatLength(): ?int
    {
        return $this->reader->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the compression enum describing the JPEG thumbnail stored in IFD1.
     *
     * EXIF 3.0 §4.6.5.1.4 defines Compression value 6 to designate JPEG-compressed
     * thumbnails stored in IFD1.
     */
    public function thumbnailCompression(): ?Compression
    {
        return Compression::fromExifValue($this->reader->enumValue($this->ifd1, ExifTag::COMPRESSION));
    }

    /**
     * Returns the tile width defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     */
    public function thumbnailTileWidth(): ?int
    {
        return $this->reader->int($this->ifd1, TiffTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileLength for tiled image storage.
     */
    public function thumbnailTileLength(): ?int
    {
        return $this->reader->int($this->ifd1, TiffTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->reader->numericList($this->ifd1, TiffTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->reader->numericList($this->ifd1, TiffTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.5.2.1 defines StripOffsets for strip-based image storage and requires
     * the tag to be omitted for JPEG-compressed data.
     *
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        if ($this->isJpegCompression($this->thumbnailCompression())) {
            return null;
        }

        return $this->reader->numericList($this->ifd1, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.5.2.3 defines StripByteCounts for strip-based image storage and requires
     * the tag to be omitted for JPEG-compressed data.
     *
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        if ($this->isJpegCompression($this->thumbnailCompression())) {
            return null;
        }

        return $this->reader->numericList($this->ifd1, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Determines whether strip-based metadata shall be omitted for JPEG-encoded payloads.
     */
    private function isJpegCompression(?Compression $compression): bool
    {
        if (!$compression instanceof Compression) {
            return false;
        }

        return in_array(
            $compression,
            [
                Compression::JPEG,
                Compression::JPEG_NEW_STYLE,
                Compression::LOSSY_JPEG,
                Compression::JPEG_2000,
            ],
            true
        );
    }
}
