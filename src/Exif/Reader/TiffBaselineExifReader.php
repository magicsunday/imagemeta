<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function count;
use function round;

/**
 * Reads rarely-used TIFF 6.0 baseline tags from IFD0.
 *
 * TIFF 6.0 §8 and §11–§22 define the tags decoded by this reader. These tags are
 * primarily for specialized printing, scanning, halftone and fax applications.
 * Most photography use cases will not need these tags.
 */
final readonly class TiffBaselineExifReader
{
    /**
     * @param IfdValueReader $reader Value reader for IFD tag extraction.
     * @param Ifd            $ifd0   Root IFD of the TIFF structure.
     */
    public function __construct(
        private IfdValueReader $reader,
        private Ifd $ifd0,
    ) {
    }

    // ========================================================================
    // Tile tags (IFD0)
    // ========================================================================

    /**
     * Returns the tile width defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     * For thumbnail tile width, use thumbnailTileWidth().
     */
    public function tileWidth(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileLength for tiled image storage.
     * For thumbnail tile length, use thumbnailTileLength().
     */
    public function tileLength(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     * For thumbnail tile offsets, use thumbnailTileOffsets().
     *
     * @return list<int>|null
     */
    public function tileOffsets(): ?array
    {
        return $this->reader->numericList($this->ifd0, TiffTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     * For thumbnail tile byte counts, use thumbnailTileByteCounts().
     *
     * @return list<int>|null
     */
    public function tileByteCounts(): ?array
    {
        return $this->reader->numericList($this->ifd0, TiffTag::TILE_BYTE_COUNTS);
    }

    // ========================================================================
    // Transfer function
    // ========================================================================

    /**
     * Returns the transfer function lookup table when available.
     *
     * EXIF 3.0 §4.6.5.3.1 defines TransferFunction as a 3×256 table of SHORT values
     * describing the tone reproduction curve.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        $values = $this->reader->numericList($this->ifd0, ExifTag::TRANSFER_FUNCTION);

        if ($values !== null) {
            return count($values) === 3 * 256 ? $values : null;
        }

        $bps = $this->bitsPerSample();

        if ($bps === null) {
            return null;
        }

        return $this->defaultTransferFunction($bps);
    }

    // ========================================================================
    // Predictor
    // ========================================================================

    /**
     * Returns the TIFF predictor value for differencing compression schemes.
     *
     * TIFF 6.0 §14 defines the Predictor tag as a mathematical operator applied before
     * compression. Valid values: 1 = No prediction (default), 2 = Horizontal differencing.
     */
    public function predictor(): int
    {
        // TIFF 6.0 §14: default is 1 (no prediction scheme).
        return $this->reader->int($this->ifd0, TiffTag::PREDICTOR) ?? 1;
    }

    // ========================================================================
    // TIFF 6.0 rarely-used baseline tags
    // ========================================================================
    /**
     * Returns NewSubfileType tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x00FE.
     */
    public function newSubfileType(): int
    {
        // TIFF 6.0 §8: default is 0 (full-resolution image data).
        return $this->reader->int($this->ifd0, TiffTag::NEW_SUBFILE_TYPE) ?? 0;
    }

    /**
     * Returns SubfileType tag value (deprecated).
     * TIFF 5.0 (deprecated in TIFF 6.0) — Tag 0x00FF.
     */
    public function subfileType(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::SUBFILE_TYPE);
    }

    /**
     * Returns Threshholding tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0107.
     */
    public function threshholding(): int
    {
        // TIFF 6.0 §8: default is 1 (No dithering or halftoning).
        return $this->reader->int($this->ifd0, TiffTag::THRESHHOLDING) ?? 1;
    }

    /**
     * Returns CellWidth tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0108.
     */
    public function cellWidth(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::CELL_WIDTH);
    }

    /**
     * Returns CellLength tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0109.
     */
    public function cellLength(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::CELL_LENGTH);
    }

    /**
     * Returns FillOrder tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x010A.
     */
    public function fillOrder(): int
    {
        // TIFF 6.0 §8: default is 1 (most significant bits first).
        return $this->reader->int($this->ifd0, TiffTag::FILL_ORDER) ?? 1;
    }

    /**
     * Returns MinSampleValue tag value.
     * TIFF 6.0 §8: default is 0 when tag is absent.
     */
    public function minSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::MIN_SAMPLE_VALUE) ?? 0;
    }

    /**
     * Returns MaxSampleValue tag value.
     * TIFF 6.0 §8: default is (2^BitsPerSample)-1 when tag is absent.
     */
    public function maxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::MAX_SAMPLE_VALUE)
            ?? ((1 << ($this->bitsPerSample() ?? 8)) - 1);
    }

    /**
     * Returns PageName tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011D.
     */
    public function pageName(): ?string
    {
        return $this->reader->str($this->ifd0, TiffTag::PAGE_NAME);
    }

    /**
     * Returns XPosition tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011E.
     */
    public function xPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::X_POSITION);
    }

    /**
     * Returns YPosition tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011F.
     */
    public function yPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::Y_POSITION);
    }

    /**
     * Returns FreeOffsets tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0120.
     */
    public function freeOffsets(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::FREE_OFFSETS);
    }

    /**
     * Returns FreeByteCounts tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0121.
     */
    public function freeByteCounts(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::FREE_BYTE_COUNTS);
    }

    /**
     * Returns GrayResponseUnit tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0122.
     */
    public function grayResponseUnit(): int
    {
        // TIFF 6.0 §8: default is 2 (hundredths of a unit).
        return $this->reader->int($this->ifd0, TiffTag::GRAY_RESPONSE_UNIT) ?? 2;
    }

    /**
     * Returns GrayResponseCurve tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0123.
     */
    public function grayResponseCurve(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::GRAY_RESPONSE_CURVE);
    }

    /**
     * Returns T4Options tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0124.
     */
    public function t4Options(): int
    {
        // TIFF 6.0 §11: default is 0 (1-D encoding).
        return $this->reader->int($this->ifd0, TiffTag::T4_OPTIONS) ?? 0;
    }

    /**
     * Returns T6Options tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0125.
     */
    public function t6Options(): int
    {
        // TIFF 6.0 §11: default is 0 (no uncompressed mode).
        return $this->reader->int($this->ifd0, TiffTag::T6_OPTIONS) ?? 0;
    }

    /**
     * Returns PageNumber tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0129.
     */
    public function pageNumber(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::PAGE_NUMBER);
    }

    /**
     * Returns ColorMap tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0140.
     */
    public function colorMap(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::COLOR_MAP);
    }

    /**
     * Returns HalftoneHints tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0141.
     */
    public function halftoneHints(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::HALFTONE_HINTS);
    }

    /**
     * Returns InkSet tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014C.
     */
    public function inkSet(): int
    {
        // TIFF 6.0 §8: default is 1 (CMYK).
        return $this->reader->int($this->ifd0, TiffTag::INK_SET) ?? 1;
    }

    /**
     * Returns InkNames tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014D.
     */
    public function inkNames(): ?string
    {
        return $this->reader->str($this->ifd0, TiffTag::INK_NAMES);
    }

    /**
     * Returns NumberOfInks tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014E.
     */
    public function numberOfInks(): int
    {
        // TIFF 6.0 §8: default is 4 (for CMYK).
        return $this->reader->int($this->ifd0, TiffTag::NUMBER_OF_INKS) ?? 4;
    }

    /**
     * Returns DotRange tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0150.
     */
    public function dotRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::DOT_RANGE);
    }

    /**
     * Returns TargetPrinter tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0151.
     */
    public function targetPrinter(): ?string
    {
        return $this->reader->str($this->ifd0, TiffTag::TARGET_PRINTER);
    }

    /**
     * Returns ExtraSamples tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0152.
     */
    public function extraSamples(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::EXTRA_SAMPLES);
    }

    /**
     * Returns SampleFormat tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0153.
     */
    public function sampleFormat(): int
    {
        // TIFF 6.0 §8: default is 1 (unsigned integer data).
        return $this->reader->int($this->ifd0, TiffTag::SAMPLE_FORMAT) ?? 1;
    }

    /**
     * Returns SMinSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0154.
     */
    public function sMinSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::S_MIN_SAMPLE_VALUE);
    }

    /**
     * Returns SMaxSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0155.
     */
    public function sMaxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::S_MAX_SAMPLE_VALUE);
    }

    /**
     * Returns TransferRange tag value.
     * TIFF 6.0 §8: default is [0, NV, 0, NV, 0, NV] where NV = (2^BitsPerSample)-1.
     */
    public function transferRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $value = $this->reader->normalisedValue($this->ifd0, TiffTag::TRANSFER_RANGE);

        if ($value !== null) {
            return $value;
        }

        $bps = $this->bitsPerSample();

        if ($bps === null) {
            return null;
        }

        $nv = (1 << $bps) - 1;

        return new ExifNumericList([0, $nv, 0, $nv, 0, $nv]);
    }

    /**
     * Returns JPEGProc tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0200.
     */
    public function jpegProc(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::JPEG_PROC);
    }

    /**
     * Returns JPEGRestartInterval tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0203.
     */
    public function jpegRestartInterval(): ?int
    {
        return $this->reader->int($this->ifd0, TiffTag::JPEG_RESTART_INTERVAL);
    }

    /**
     * Returns JPEGLosslessPredictors tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0205.
     */
    public function jpegLosslessPredictors(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::JPEG_LOSSLESS_PREDICTORS);
    }

    /**
     * Returns JPEGPointTransforms tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0206.
     */
    public function jpegPointTransforms(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::JPEG_POINT_TRANSFORMS);
    }

    /**
     * Returns JPEGQTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0207.
     */
    public function jpegQTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::JPEG_Q_TABLES);
    }

    /**
     * Returns JPEGDCTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0208.
     */
    public function jpegDCTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::JPEG_DC_TABLES);
    }

    /**
     * Returns JPEGACTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0209.
     */
    public function jpegACTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->reader->normalisedValue($this->ifd0, TiffTag::JPEG_AC_TABLES);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Returns the first BitsPerSample component from IFD0.
     *
     * Used internally by methods that compute defaults based on bit depth
     * (maxSampleValue, transferFunction, transferRange).
     */
    private function bitsPerSample(): ?int
    {
        $list = $this->reader->numericList($this->ifd0, ExifTag::BITS_PER_SAMPLE);

        if ($list !== null) {
            return $list[0];
        }

        // TIFF context: default 8 when Compression tag is present
        if ($this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry) {
            return 8;
        }

        return null;
    }

    /**
     * Computes the NTSC gamma 2.2 transfer function table per TIFF 6.0.
     *
     * Returns 3 × (2^bitsPerSample) SHORT values mapping sample values
     * to the 0–65535 linear range. The same single-channel table is
     * replicated for R, G and B.
     *
     * @return list<int>
     */
    private function defaultTransferFunction(int $bitsPerSample): array
    {
        /** @var array<int, list<int>> $cache */
        static $cache = [];

        if (isset($cache[$bitsPerSample])) {
            return $cache[$bitsPerSample];
        }

        $n     = 1 << $bitsPerSample;
        $max   = $n - 1;
        $table = [];

        for ($i = 0; $i < $n; ++$i) {
            $table[] = (int) round(($i / $max) ** 2.2 * 65535);
        }

        // Same curve for all three channels
        $result                = [...$table, ...$table, ...$table];
        $cache[$bitsPerSample] = $result;

        return $result;
    }
}
