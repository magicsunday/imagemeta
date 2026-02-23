<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * TIFF strip, tile, and JPEG interchange data layout.
 */
final readonly class TiffLayout
{
    /**
     * @param int|null       $rowsPerStrip                Number of rows per TIFF strip.
     * @param list<int>|null $stripOffsets                File offsets for TIFF strips.
     * @param list<int>|null $stripByteCounts             Byte counts for each TIFF strip.
     * @param int|null       $tileWidth                   Width of an individual tile when tiling is used.
     * @param int|null       $tileLength                  Length of an individual tile when tiling is used.
     * @param list<int>|null $tileOffsets                 File offsets for TIFF tiles.
     * @param list<int>|null $tileByteCounts              Byte counts for each TIFF tile.
     * @param int|null       $jpegInterchangeFormat       Offset to the JPEG interchange stream.
     * @param int|null       $jpegInterchangeFormatLength Byte length of the JPEG interchange stream.
     */
    public function __construct(
        public ?int $rowsPerStrip = null,
        public ?array $stripOffsets = null,
        public ?array $stripByteCounts = null,
        public ?int $tileWidth = null,
        public ?int $tileLength = null,
        public ?array $tileOffsets = null,
        public ?array $tileByteCounts = null,
        public ?int $jpegInterchangeFormat = null,
        public ?int $jpegInterchangeFormatLength = null,
    ) {
    }
}
