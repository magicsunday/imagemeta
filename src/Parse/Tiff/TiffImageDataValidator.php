<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function count;
use function intdiv;
use function is_int;
use function sprintf;

/**
 * Validates strip and tile image-data layout consistency.
 *
 * TIFF 6.0 and EXIF 3.0 define strip/tile tag count constraints and data-range
 * bounds checked by this validator.
 */
final readonly class TiffImageDataValidator
{
    public function __construct(
        private TiffValidationSupport $support,
    ) {
    }

    /**
     * Validates strip layout consistency for non-JPEG primary image data.
     *
     * EXIF 3.0 §4.6.5.2.2 and §4.6.5.2.3 require RowsPerStrip and tie strip tag
     * counts to StripsPerImage, with planar-separate layout multiplying by
     * SamplesPerPixel (EXIF 3.0 §4.6.5.1.10).
     */
    public function validateStripLayoutConsistency(Ifd $ifd0): void
    {
        $stripOffsetsEntry    = $ifd0->get(ExifTag::STRIP_OFFSETS);
        $stripByteCountsEntry = $ifd0->get(ExifTag::STRIP_BYTE_COUNTS);

        $hasStripFields = ($stripOffsetsEntry instanceof IfdEntry)
            || ($stripByteCountsEntry instanceof IfdEntry);

        if (!$hasStripFields) {
            return;
        }

        $hasTileFields = ($ifd0->get(TiffTag::TILE_WIDTH) instanceof IfdEntry)
            || ($ifd0->get(TiffTag::TILE_LENGTH) instanceof IfdEntry)
            || ($ifd0->get(TiffTag::TILE_OFFSETS) instanceof IfdEntry)
            || ($ifd0->get(TiffTag::TILE_BYTE_COUNTS) instanceof IfdEntry);

        if ($hasTileFields) {
            return;
        }

        $rowsPerStripEntry = $ifd0->get(ExifTag::ROWS_PER_STRIP);

        if ((!$rowsPerStripEntry instanceof IfdEntry) || !is_int($rowsPerStripEntry->value) || ($rowsPerStripEntry->value <= 0)) {
            throw new ParseError(
                'RowsPerStrip must be a positive integer when strip tags are present per EXIF 3.0 §4.6.5.2.2.',
                1987,
            );
        }

        $imageLengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);

        if ((!$imageLengthEntry instanceof IfdEntry) || !is_int($imageLengthEntry->value) || ($imageLengthEntry->value <= 0)) {
            return;
        }

        $stripsPerImage = intdiv($imageLengthEntry->value + $rowsPerStripEntry->value - 1, $rowsPerStripEntry->value);

        [$expectedCount] = $this->resolvePlanarAdjustedCount($ifd0, $stripsPerImage);

        if ($stripOffsetsEntry instanceof IfdEntry) {
            $offsetCount = $this->countStripFieldValues($stripOffsetsEntry);

            if ($offsetCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripOffsets count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.1/§4.6.5.2.2.',
                    $offsetCount,
                    $expectedCount,
                ), 1988);
            }
        }

        if ($stripByteCountsEntry instanceof IfdEntry) {
            $byteCountCount = $this->countStripFieldValues($stripByteCountsEntry);

            if ($byteCountCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripByteCounts count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.3/§4.6.5.2.2.',
                    $byteCountCount,
                    $expectedCount,
                ), 1989);
            }
        }

        if (($stripOffsetsEntry instanceof IfdEntry) && ($stripByteCountsEntry instanceof IfdEntry)) {
            $this->validateCountedImageDataRanges(
                ExifTag::STRIP_OFFSETS,
                $this->countedImageDataValues($stripOffsetsEntry, ExifTag::STRIP_OFFSETS),
                ExifTag::STRIP_BYTE_COUNTS,
                $this->countedImageDataValues($stripByteCountsEntry, ExifTag::STRIP_BYTE_COUNTS),
            );
        }
    }

    /**
     * Validates tiled TIFF layout consistency for non-JPEG primary image data.
     *
     * TIFF 6.0 tiled images require TileWidth/TileLength multiples of 16 and tile
     * offset/byte-count arrays sized to TilesPerImage. For planar separate images
     * (PlanarConfiguration=2), counts are multiplied by SamplesPerPixel.
     */
    public function validateTileLayoutConsistency(Ifd $ifd0): void
    {
        $tileWidthEntry      = $ifd0->get(TiffTag::TILE_WIDTH);
        $tileLengthEntry     = $ifd0->get(TiffTag::TILE_LENGTH);
        $tileOffsetsEntry    = $ifd0->get(TiffTag::TILE_OFFSETS);
        $tileByteCountsEntry = $ifd0->get(TiffTag::TILE_BYTE_COUNTS);

        $hasTileFields = ($tileWidthEntry instanceof IfdEntry)
            || ($tileLengthEntry instanceof IfdEntry)
            || ($tileOffsetsEntry instanceof IfdEntry)
            || ($tileByteCountsEntry instanceof IfdEntry);

        if (!$hasTileFields) {
            return;
        }

        $this->validateTileStripExclusion($ifd0);

        [$tileWidth, $tileLength] = $this->validateTileDimensions($tileWidthEntry, $tileLengthEntry);

        if ((!$tileOffsetsEntry instanceof IfdEntry) || (!$tileByteCountsEntry instanceof IfdEntry)) {
            throw new ParseError(
                'TileOffsets and TileByteCounts must both be present for tiled image layout.',
                1699,
            );
        }

        $this->validateTileCountArrays($ifd0, $tileWidth, $tileLength, $tileOffsetsEntry, $tileByteCountsEntry);
    }

    /**
     * Rejects IFDs that mix strip and tile layout tags.
     *
     * TIFF 6.0 requires a single image organization per IFD: either strip-based
     * or tile-based, never both.
     */
    private function validateTileStripExclusion(Ifd $ifd0): void
    {
        $hasStripFields = ($ifd0->get(ExifTag::ROWS_PER_STRIP) instanceof IfdEntry)
            || ($ifd0->get(ExifTag::STRIP_OFFSETS) instanceof IfdEntry)
            || ($ifd0->get(ExifTag::STRIP_BYTE_COUNTS) instanceof IfdEntry);

        if ($hasStripFields) {
            throw new ParseError(
                'Strip and tile layout tags must not be mixed in the same IFD for one image organization.',
                1694,
            );
        }
    }

    /**
     * Validates TileWidth/TileLength presence, positivity and mod-16 constraint.
     *
     * TIFF 6.0 tiled images require TileWidth and TileLength to be positive
     * integer multiples of 16.
     *
     * @return array{0: int, 1: int} Validated tile width and tile length.
     */
    private function validateTileDimensions(?IfdEntry $tileWidthEntry, ?IfdEntry $tileLengthEntry): array
    {
        if ((!$tileWidthEntry instanceof IfdEntry) || !is_int($tileWidthEntry->value) || ($tileWidthEntry->value <= 0)) {
            throw new ParseError('TileWidth must be a positive integer when tiled layout tags are present.', 1695);
        }

        if ((!$tileLengthEntry instanceof IfdEntry) || !is_int($tileLengthEntry->value) || ($tileLengthEntry->value <= 0)) {
            throw new ParseError('TileLength must be a positive integer when tiled layout tags are present.', 1696);
        }

        if (($tileWidthEntry->value % 16) !== 0) {
            throw new ParseError(
                sprintf('TileWidth %d must be an integer multiple of 16.', $tileWidthEntry->value),
                1697,
            );
        }

        if (($tileLengthEntry->value % 16) !== 0) {
            throw new ParseError(
                sprintf('TileLength %d must be an integer multiple of 16.', $tileLengthEntry->value),
                1698,
            );
        }

        return [$tileWidthEntry->value, $tileLengthEntry->value];
    }

    /**
     * Validates TileOffsets/TileByteCounts array sizes against computed TilesPerImage.
     *
     * TIFF 6.0 tiled images require tile offset/byte-count arrays sized to
     * TilesPerImage. For planar separate images (PlanarConfiguration=2),
     * counts are multiplied by SamplesPerPixel.
     */
    private function validateTileCountArrays(
        Ifd $ifd0,
        int $tileWidth,
        int $tileLength,
        IfdEntry $tileOffsetsEntry,
        IfdEntry $tileByteCountsEntry,
    ): void {
        $imageWidthEntry  = $ifd0->get(ExifTag::IMAGE_WIDTH);
        $imageLengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);

        if ((!$imageWidthEntry instanceof IfdEntry) || !is_int($imageWidthEntry->value) || ($imageWidthEntry->value <= 0) || (!$imageLengthEntry instanceof IfdEntry) || !is_int($imageLengthEntry->value) || ($imageLengthEntry->value <= 0)) {
            return;
        }

        $tilesAcross = intdiv($imageWidthEntry->value + $tileWidth - 1, $tileWidth);
        $tilesDown   = intdiv($imageLengthEntry->value + $tileLength - 1, $tileLength);

        $tilesPerImage = $tilesAcross * $tilesDown;

        [$expectedCount, $planarConfiguration] = $this->resolvePlanarAdjustedCount($ifd0, $tilesPerImage);

        $this->validateTileOffsetAndByteCountSizes(
            $tileOffsetsEntry,
            $tileByteCountsEntry,
            $expectedCount,
            $tilesAcross,
            $tilesDown,
            $planarConfiguration,
        );
    }

    /**
     * Validates TileOffsets/TileByteCounts array sizes and data ranges.
     *
     * TIFF 6.0 tiled images require tile offset/byte-count arrays sized
     * to TilesPerImage (adjusted for PlanarConfiguration=2).
     */
    private function validateTileOffsetAndByteCountSizes(
        IfdEntry $tileOffsetsEntry,
        IfdEntry $tileByteCountsEntry,
        int $expectedCount,
        int $tilesAcross,
        int $tilesDown,
        int $planarConfiguration,
    ): void {
        $offsetCount = $this->countStripFieldValues($tileOffsetsEntry);

        if ($offsetCount !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'TileOffsets count %d does not match expected tile count %d (TilesAcross=%d, TilesDown=%d, PlanarConfiguration=%d).',
                    $offsetCount,
                    $expectedCount,
                    $tilesAcross,
                    $tilesDown,
                    $planarConfiguration,
                ),
                1700,
            );
        }

        $byteCountCount = $this->countStripFieldValues($tileByteCountsEntry);

        if ($byteCountCount !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'TileByteCounts count %d does not match expected tile count %d (TilesAcross=%d, TilesDown=%d, PlanarConfiguration=%d).',
                    $byteCountCount,
                    $expectedCount,
                    $tilesAcross,
                    $tilesDown,
                    $planarConfiguration,
                ),
                1701,
            );
        }

        $this->validateCountedImageDataRanges(
            TiffTag::TILE_OFFSETS,
            $this->countedImageDataValues($tileOffsetsEntry, TiffTag::TILE_OFFSETS),
            TiffTag::TILE_BYTE_COUNTS,
            $this->countedImageDataValues($tileByteCountsEntry, TiffTag::TILE_BYTE_COUNTS),
        );
    }

    /**
     * Resolves expected image-data count adjusted for PlanarConfiguration.
     *
     * For planar separate images (PlanarConfiguration=2), the base count
     * is multiplied by SamplesPerPixel.
     *
     * @return array{0: int, 1: int} Expected count and PlanarConfiguration.
     */
    private function resolvePlanarAdjustedCount(Ifd $ifd, int $baseCount): array
    {
        $planarConfiguration = 1;
        $planarEntry         = $ifd->get(ExifTag::PLANAR_CONFIGURATION);

        if (($planarEntry instanceof IfdEntry) && is_int($planarEntry->value)) {
            $planarConfiguration = $planarEntry->value;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if (($samplesEntry instanceof IfdEntry) && is_int($samplesEntry->value) && ($samplesEntry->value > 0)) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedCount = $baseCount;

        if ($planarConfiguration === 2) {
            $expectedCount *= $samplesPerPixel;
        }

        return [$expectedCount, $planarConfiguration];
    }

    /**
     * Returns the number of values encoded in a strip offset/count field.
     */
    private function countStripFieldValues(IfdEntry $entry): int
    {
        if (is_int($entry->value)) {
            return 1;
        }

        if ($entry->value instanceof ExifNumericList) {
            return count($entry->value->values);
        }

        return 0;
    }

    /**
     * Converts strip/tile offset or byte-count field values to integer lists.
     *
     * @return list<int>
     */
    private function countedImageDataValues(IfdEntry $entry, int $tag): array
    {
        if (is_int($entry->value)) {
            return [$entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $values = [];

            foreach ($entry->value->values as $index => $component) {
                if (!is_int($component)) {
                    throw new ParseError(sprintf(
                        '%s contains a non-integer component at index %d.',
                        TiffValidationSupport::countedImageDataTagName($tag),
                        $index,
                    ), 1702);
                }

                $values[] = $component;
            }

            return $values;
        }

        throw new ParseError(sprintf(
            '%s has unsupported value representation for range validation.',
            TiffValidationSupport::countedImageDataTagName($tag),
        ), 2075);
    }

    /**
     * Validates strip/tile offset+byteCount pairs against TIFF blob bounds.
     *
     * @param int[] $offsets
     * @param int[] $byteCounts
     */
    private function validateCountedImageDataRanges(
        int $offsetTag,
        array $offsets,
        int $byteCountTag,
        array $byteCounts,
    ): void {
        $blobSize  = $this->support->buffer()->size();
        $pairCount = count($offsets);

        for ($index = 0; $index < $pairCount; ++$index) {
            $offset    = $offsets[$index] ?? 0;
            $byteCount = $byteCounts[$index] ?? 0;

            if (($offset < 0) || ($byteCount < 0) || ($offset > $blobSize) || ($byteCount > $blobSize) || ($offset > ($blobSize - $byteCount))) {
                throw new ParseError(
                    sprintf(
                        '%s[%d]=%d with %s[%d]=%d exceeds TIFF data bounds (size=%d).',
                        TiffValidationSupport::countedImageDataTagName($offsetTag),
                        $index,
                        $offset,
                        TiffValidationSupport::countedImageDataTagName($byteCountTag),
                        $index,
                        $byteCount,
                        $blobSize,
                    ),
                    2076,
                );
            }
        }
    }
}
