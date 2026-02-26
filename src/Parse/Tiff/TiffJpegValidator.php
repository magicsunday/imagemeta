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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;

use function in_array;
use function is_int;
use function sprintf;

/**
 * Validates JPEG-related TIFF 6.0 structural and semantic constraints on IFD entries.
 *
 * TIFF 6.0 Section 22 defines JPEG field rules, table pointer semantics, and
 * cross-tag dependencies validated by this class.
 */
final readonly class TiffJpegValidator
{
    public function __construct(
        private TiffValidationSupport $support,
    ) {
    }

    /**
     * Validates JPEGProc structural and cross-tag compression coupling rules.
     *
     * TIFF 6.0 Section 22 (JPEG Fields) defines JPEGProc as SHORT[1] with values
     * {1,14}, mandatory for JPEG-compressed image data and invalid otherwise.
     */
    public function validateJpegProcTag(Ifd $ifd): void
    {
        $jpegProc    = $ifd->get(TiffTag::JPEG_PROC);
        $compression = $ifd->get(ExifTag::COMPRESSION);

        $isJpegCompression = ($compression instanceof IfdEntry)
            && is_int($compression->value)
            && ($compression->value === Compression::Jpeg->value);

        if ($jpegProc instanceof IfdEntry) {
            if (($jpegProc->type !== TiffConst::TYPE_SHORT) || ($jpegProc->count !== 1) || !is_int($jpegProc->value)) {
                throw new ParseError('JPEGProc must be SHORT[1].', 1826);
            }

            if (!in_array($jpegProc->value, [1, 14], true)) {
                throw new ParseError(
                    sprintf('JPEGProc value %d is invalid; allowed values are 1 or 14.', $jpegProc->value),
                    1827,
                );
            }

            if (!$isJpegCompression) {
                throw new ParseError('JPEGProc is only valid when Compression=6 (JPEG).', 1828);
            }

            return;
        }

        // TIFF 6.0 Section 22 requires JPEGProc for Compression=6 (old-style
        // JPEG), but Compression=6 was deprecated by TIFF Technical Note 2.
        // Many encoders that use Compression=6 in embedded thumbnails omit
        // JPEGProc because the JPEG stream's SOF marker is self-describing.
    }

    /**
     * Validates lossless JPEG predictor/point-transform semantics.
     *
     * TIFF 6.0 Section 22 defines JPEGLosslessPredictors and JPEGPointTransforms
     * as SHORT arrays with count SamplesPerPixel. JPEGLosslessPredictors is
     * mandatory for JPEGProc=14 and predictor values are limited to 1..7.
     * JPEGPointTransforms defaults to zero per component when omitted.
     */
    public function validateJpegLosslessTags(Ifd $ifd): void
    {
        [$jpegProc, $samplesPerPixel] = $this->resolveJpegProcAndSamplesPerPixel($ifd);

        $losslessPredictorsEntry = $ifd->get(TiffTag::JPEG_LOSSLESS_PREDICTORS);
        if ($losslessPredictorsEntry instanceof IfdEntry) {
            if (
                ($losslessPredictorsEntry->type !== TiffConst::TYPE_SHORT)
                || ($losslessPredictorsEntry->count !== $samplesPerPixel)
            ) {
                throw new ParseError('JPEGLosslessPredictors must be SHORT[SamplesPerPixel].', 1836);
            }

            $predictorValues = $this->support->extractIntegerTagComponents($losslessPredictorsEntry, 'JPEGLosslessPredictors');
            foreach ($predictorValues as $componentIndex => $predictorValue) {
                if (($predictorValue >= 1) && ($predictorValue <= 7)) {
                    continue;
                }

                throw new ParseError(
                    sprintf(
                        'JPEGLosslessPredictors component %d value %d is invalid; allowed values are 1..7.',
                        $componentIndex,
                        $predictorValue,
                    ),
                    1837,
                );
            }
        }

        $pointTransformsEntry = $ifd->get(TiffTag::JPEG_POINT_TRANSFORMS);
        if ($pointTransformsEntry instanceof IfdEntry) {
            if (
                ($pointTransformsEntry->type !== TiffConst::TYPE_SHORT)
                || ($pointTransformsEntry->count !== $samplesPerPixel)
            ) {
                throw new ParseError('JPEGPointTransforms must be SHORT[SamplesPerPixel].', 1838);
            }

            $this->support->extractIntegerTagComponents($pointTransformsEntry, 'JPEGPointTransforms');
        }

        if ($jpegProc === 14) {
            if (!$losslessPredictorsEntry instanceof IfdEntry) {
                throw new ParseError('JPEGProc=14 requires JPEGLosslessPredictors.', 1839);
            }

            return;
        }

        if ($losslessPredictorsEntry instanceof IfdEntry) {
            throw new ParseError('JPEGLosslessPredictors is only valid when JPEGProc=14.', 1840);
        }

        if ($pointTransformsEntry instanceof IfdEntry) {
            throw new ParseError('JPEGPointTransforms is only valid when JPEGProc=14.', 1841);
        }
    }

    /**
     * Validates JPEGRestartInterval structure and JPEG-only applicability.
     *
     * TIFF 6.0 Section 22 defines JPEGRestartInterval as SHORT[1] in the JPEG
     * field set controlled by Compression=6 and JPEGProc.
     */
    public function validateJpegRestartIntervalTag(Ifd $ifd): void
    {
        $restartIntervalEntry = $ifd->get(TiffTag::JPEG_RESTART_INTERVAL);
        if (!$restartIntervalEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($restartIntervalEntry->type !== TiffConst::TYPE_SHORT)
            || ($restartIntervalEntry->count !== 1)
            || !is_int($restartIntervalEntry->value)
        ) {
            throw new ParseError('JPEGRestartInterval must be SHORT[1].', 1851);
        }

        $compressionEntry  = $ifd->get(ExifTag::COMPRESSION);
        $isJpegCompression = ($compressionEntry instanceof IfdEntry)
            && is_int($compressionEntry->value)
            && ($compressionEntry->value === Compression::Jpeg->value);

        if (!$isJpegCompression) {
            throw new ParseError('JPEGRestartInterval is only valid when Compression=6 (JPEG).', 1852);
        }

        // JPEGProc may be absent when Compression=6 — see validateJpegProcTag().
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        if (
            ($jpegProcEntry instanceof IfdEntry)
            && (!is_int($jpegProcEntry->value) || !in_array($jpegProcEntry->value, [1, 14], true))
        ) {
            throw new ParseError('JPEGRestartInterval requires valid JPEGProc metadata.', 1853);
        }
    }

    /**
     * Validates JPEG table offset tags and process-specific requirements.
     *
     * TIFF 6.0 Section 22 defines JPEGQTables, JPEGDCTables and JPEGACTables as
     * LONG arrays with count SamplesPerPixel whose values are offsets within the
     * TIFF blob. Mandatory fields depend on the JPEG process (JPEGProc).
     */
    public function validateJpegTableTags(Ifd $ifd): void
    {
        [$jpegProc, $samplesPerPixel] = $this->resolveJpegProcAndSamplesPerPixel($ifd);

        $jpegQTablesEntry  = $ifd->get(TiffTag::JPEG_Q_TABLES);
        $jpegDcTablesEntry = $ifd->get(TiffTag::JPEG_DC_TABLES);
        $jpegAcTablesEntry = $ifd->get(TiffTag::JPEG_AC_TABLES);

        if ($jpegQTablesEntry instanceof IfdEntry) {
            if (($jpegQTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegQTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGQTables must be LONG[SamplesPerPixel].', 1842);
            }

            $this->validateJpegTableOffsets($jpegQTablesEntry, 'JPEGQTables');
        }

        if ($jpegDcTablesEntry instanceof IfdEntry) {
            if (($jpegDcTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegDcTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGDCTables must be LONG[SamplesPerPixel].', 1843);
            }

            $this->validateJpegTableOffsets($jpegDcTablesEntry, 'JPEGDCTables');
        }

        if ($jpegAcTablesEntry instanceof IfdEntry) {
            if (($jpegAcTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegAcTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGACTables must be LONG[SamplesPerPixel].', 1844);
            }

            $this->validateJpegTableOffsets($jpegAcTablesEntry, 'JPEGACTables');
        }

        $hasJpegTableTags = ($jpegQTablesEntry instanceof IfdEntry)
            || ($jpegDcTablesEntry instanceof IfdEntry)
            || ($jpegAcTablesEntry instanceof IfdEntry);

        if (!$hasJpegTableTags) {
            return;
        }

        if ($jpegProc === 1) {
            if (!$jpegDcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGDCTables is required when JPEGProc=1.', 1845);
            }

            if (!($jpegQTablesEntry instanceof IfdEntry) || !($jpegAcTablesEntry instanceof IfdEntry)) {
                throw new ParseError('JPEGQTables and JPEGACTables are required when JPEGProc=1.', 1846);
            }

            return;
        }

        if ($jpegProc === 14) {
            if (!$jpegDcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGDCTables is required when JPEGProc=14.', 1847);
            }

            if ($jpegAcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGACTables are not used when JPEGProc=14.', 1848);
            }

            return;
        }

        throw new ParseError('JPEG table tags are only valid when JPEGProc is 1 or 14.', 1849);
    }

    /**
     * Validates JPEGInterchangeFormat/JPEGInterchangeFormatLength pair semantics.
     *
     * TIFF 6.0 Section 22 defines these fields as a coupled offset/length pair
     * for embedded JPEG interchange streams.
     */
    public function validateJpegInterchangePairTags(Ifd $ifd): void
    {
        $offsetEntry = $ifd->get(ExifTag::JPEG_INTERCHANGE_FORMAT);
        $lengthEntry = $ifd->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);

        if (!($offsetEntry instanceof IfdEntry) && !($lengthEntry instanceof IfdEntry)) {
            return;
        }

        if ($lengthEntry instanceof IfdEntry && !($offsetEntry instanceof IfdEntry)) {
            // EXIF 3.0 §4.6.5.1.6 defines offset/length as a pair, but some
            // files provide only the length tag; skip thumbnail extraction.
            return;
        }

        if (!($offsetEntry instanceof IfdEntry) || !is_int($offsetEntry->value)) {
            throw new ParseError('JPEGInterchangeFormat must be LONG[1].', 1831);
        }

        if ($offsetEntry->value <= 0) {
            if ($lengthEntry instanceof IfdEntry) {
                throw new ParseError(
                    'JPEGInterchangeFormatLength is invalid when JPEGInterchangeFormat is zero.',
                    1832,
                );
            }

            return;
        }

        // Postel's Law: treat zero/missing length as "no thumbnail".
        if (!($lengthEntry instanceof IfdEntry) || !is_int($lengthEntry->value) || $lengthEntry->value <= 0) {
            return;
        }

        // Postel's Law: treat out-of-bounds range as "no thumbnail".
    }

    /**
     * Resolves JPEGProc value and SamplesPerPixel from the IFD.
     *
     * @return array{0: int|null, 1: int} JPEGProc value (or null) and SamplesPerPixel.
     */
    private function resolveJpegProcAndSamplesPerPixel(Ifd $ifd): array
    {
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        $jpegProc      = (($jpegProcEntry instanceof IfdEntry) && is_int($jpegProcEntry->value))
            ? $jpegProcEntry->value
            : null;

        $samplesPerPixelEntry = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        $samplesPerPixel      = 1;

        if (($samplesPerPixelEntry instanceof IfdEntry) && is_int($samplesPerPixelEntry->value) && ($samplesPerPixelEntry->value > 0)) {
            $samplesPerPixel = $samplesPerPixelEntry->value;
        }

        return [$jpegProc, $samplesPerPixel];
    }

    /**
     * Validates that all JPEG table offsets point inside the TIFF blob.
     *
     * TIFF 6.0 Section 22 uses LONG offsets for JPEG table pointers.
     */
    private function validateJpegTableOffsets(IfdEntry $entry, string $tagName): void
    {
        $tableOffsets = $this->support->extractIntegerTagComponents($entry, $tagName);
        $blobSize     = $this->support->buffer()->size();

        foreach ($tableOffsets as $componentIndex => $tableOffset) {
            if (($tableOffset > 0) && ($tableOffset < $blobSize)) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s component %d offset %d is outside TIFF bounds 1..%d.',
                    $tagName,
                    $componentIndex,
                    $tableOffset,
                    $blobSize - 1,
                ),
                1850,
            );
        }
    }
}
