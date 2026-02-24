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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;

use function count;
use function in_array;
use function is_int;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Validates TIFF 6.0 tag-level constraints and cross-tag semantic rules.
 *
 * Covers enhanced IFD rules, resolution equality, compression domain,
 * fax options, fill order, subfile/page tags, threshholding/cell tags,
 * position tags, free-space bookkeeping, predictor coupling, and image
 * dimension checks.
 */
final readonly class TiffTagConstraintValidator
{
    public function __construct(
        private TiffValidationSupport $support,
    ) {
    }

    /**
     * Validates that an Enhanced Image IFD (NewSubfileType bit 4) carries a
     * non-empty EnhanceParams tag as required by DNG 1.5+.
     */
    public function validateEnhancedIfd(Ifd $ifd): void
    {
        $entry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);
        if (!$entry instanceof IfdEntry || !is_int($entry->value)) {
            return;
        }

        if (($entry->value & 16) === 0) {
            return;
        }

        $enhance = $ifd->get(DngTag::ENHANCE_PARAMS);
        if (!$enhance instanceof IfdEntry || !is_string($enhance->value)) {
            throw new ParseError('Enhanced IFD (NewSubfileType bit 4) requires an EnhanceParams tag per DNG 1.5.', 1323);
        }

        if (rtrim($enhance->value, "\0") === '') {
            throw new ParseError('EnhanceParams must not be empty for an Enhanced IFD per DNG 1.5.', 1884);
        }
    }

    /**
     * Validates that XResolution and YResolution carry the same value when both present.
     *
     * EXIF 3.0 §4.6.5.1.8/§4.6.5.1.9 require identical values in both tags.
     */
    public function validateResolutionEquality(Ifd $ifd): void
    {
        $xRes = $ifd->get(ExifTag::X_RESOLUTION);
        $yRes = $ifd->get(ExifTag::Y_RESOLUTION);

        if (!$xRes instanceof IfdEntry || !$yRes instanceof IfdEntry) {
            return;
        }

        if (!$xRes->value instanceof ExifRational || !$yRes->value instanceof ExifRational) {
            return;
        }

        if ($xRes->value->numerator !== $yRes->value->numerator || $xRes->value->denominator !== $yRes->value->denominator) {
            throw new ParseError(sprintf(
                'XResolution (%d/%d) must equal YResolution (%d/%d) per EXIF 3.0 §4.6.5.1.8.',
                $xRes->value->numerator,
                $xRes->value->denominator,
                $yRes->value->numerator,
                $yRes->value->denominator,
            ), 1325);
        }
    }

    /**
     * Validates Compression tag values per EXIF-specific domain rules.
     *
     * EXIF 3.0 §4.6.5.1.4: In JPEG context, IFD0 allows only 1 (uncompressed);
     * IFD1 allows 1 or 6.  Standalone TIFF/DNG/NEF containers use many
     * compression methods (LZW, Deflate, etc.), so the IFD0 restriction is
     * only enforced in JPEG context.
     */
    public function validateCompressionDomain(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext): void
    {
        if ($jpegContext) {
            $entry = $ifd0->get(ExifTag::COMPRESSION);

            if (
                $entry instanceof IfdEntry
                && is_int($entry->value)
                && $entry->value !== 1
            ) {
                throw new ParseError(sprintf(
                    'Compression value %d in IFD0 is invalid; only 1 (uncompressed) is allowed.',
                    $entry->value,
                ), 1351);
            }
        }

        if (!$ifd1 instanceof Ifd) {
            return;
        }

        $thumbEntry = $ifd1->get(ExifTag::COMPRESSION);

        if (
            $thumbEntry instanceof IfdEntry
            && is_int($thumbEntry->value)
            && $thumbEntry->value !== 1
            && $thumbEntry->value !== 6
        ) {
            throw new ParseError(sprintf(
                'Compression value %d in IFD1 is invalid; only 1 or 6 is allowed.',
                $thumbEntry->value,
            ), 1352);
        }
    }

    /**
     * Validates TIFF fax option tags T4Options/T6Options coupling and bitfield domains.
     *
     * TIFF 6.0:
     * - T4Options (Tag 292): LONG[1], only with Compression=3, bits 0..2 allowed.
     * - T6Options (Tag 293): LONG[1], only with Compression=4, bit 1 allowed; bit 0 and higher bits must be 0.
     */
    public function validateFaxOptionTags(Ifd $ifd): void
    {
        $t4Options = $ifd->get(TiffTag::T4_OPTIONS);

        if ($t4Options instanceof IfdEntry) {
            if (($t4Options->type !== TiffConst::TYPE_LONG) || ($t4Options->count !== 1) || !is_int($t4Options->value)) {
                throw new ParseError('T4Options must be LONG[1].', 1702);
            }

            $compression = $ifd->get(ExifTag::COMPRESSION);
            if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 3)) {
                throw new ParseError('T4Options is only valid when Compression = 3 (CCITT Group 3).', 1703);
            }

            if (($t4Options->value & ~0b111) !== 0) {
                throw new ParseError(
                    sprintf('T4Options has reserved bits set (value=0x%X); only bits 0..2 are allowed.', $t4Options->value),
                    1704,
                );
            }
        }

        $t6Options = $ifd->get(TiffTag::T6_OPTIONS);

        if (!$t6Options instanceof IfdEntry) {
            return;
        }

        if (($t6Options->type !== TiffConst::TYPE_LONG) || ($t6Options->count !== 1) || !is_int($t6Options->value)) {
            throw new ParseError('T6Options must be LONG[1].', 1705);
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);
        if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 4)) {
            throw new ParseError('T6Options is only valid when Compression = 4 (CCITT Group 4).', 1706);
        }

        if (($t6Options->value & 0b1) !== 0) {
            throw new ParseError(
                sprintf('T6Options bit 0 is reserved and must be 0 (value=0x%X).', $t6Options->value),
                1707,
            );
        }

        if (($t6Options->value & ~0b10) !== 0) {
            throw new ParseError(
                sprintf('T6Options has reserved bits set (value=0x%X); only bit 1 is allowed.', $t6Options->value),
                1708,
            );
        }
    }

    /**
     * Validates TIFF FillOrder domain and usage constraints.
     *
     * TIFF 6.0 (Tag 266 / FillOrder):
     * - SHORT[1], values {1,2}, default 1.
     * - FillOrder=2 is intended for bilevel data (BitsPerSample=1) and
     *   uncompressed or CCITT compression families.
     */
    public function validateFillOrderTag(Ifd $ifd): void
    {
        $fillOrderEntry = $ifd->get(TiffTag::FILL_ORDER);

        if (!$fillOrderEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($fillOrderEntry->type !== TiffConst::TYPE_SHORT)
            || ($fillOrderEntry->count !== 1)
            || !is_int($fillOrderEntry->value)
        ) {
            throw new ParseError('FillOrder must be SHORT[1].', 1752);
        }

        if (($fillOrderEntry->value !== 1) && ($fillOrderEntry->value !== 2)) {
            throw new ParseError(
                sprintf('FillOrder value %d is invalid; allowed values are 1 or 2.', $fillOrderEntry->value),
                1753,
            );
        }

        if ($fillOrderEntry->value !== 2) {
            return;
        }

        $bitsPerSampleEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsPerSampleEntry instanceof IfdEntry) {
            throw new ParseError('FillOrder=2 requires BitsPerSample=1.', 1754);
        }

        $bitDepth = null;

        if (is_int($bitsPerSampleEntry->value)) {
            $bitDepth = $bitsPerSampleEntry->value;
        } elseif ($bitsPerSampleEntry->value instanceof ExifNumericList) {
            $firstComponent = $bitsPerSampleEntry->value->values[0] ?? null;
            if (is_int($firstComponent)) {
                $bitDepth = $firstComponent;
            }
        }

        if ($bitDepth !== 1) {
            throw new ParseError(
                sprintf('FillOrder=2 requires BitsPerSample=1, got %s.', $bitDepth !== null ? (string) $bitDepth : 'missing'),
                1754,
            );
        }

        $compressionCode  = 1;
        $compressionEntry = $ifd->get(ExifTag::COMPRESSION);
        if ($compressionEntry instanceof IfdEntry && is_int($compressionEntry->value)) {
            $compressionCode = $compressionEntry->value;
        }

        if (!in_array($compressionCode, [1, 2, 3, 4], true)) {
            throw new ParseError(
                sprintf(
                    'FillOrder=2 is only compatible with Compression {1,2,3,4}, got %d.',
                    $compressionCode,
                ),
                1755,
            );
        }
    }

    /**
     * Validates TIFF subfile/page tags for baseline semantics.
     *
     * TIFF 6.0:
     * - NewSubfileType: LONG[1] bitfield (bits 0..2 only in baseline TIFF).
     * - SubfileType (deprecated): SHORT[1], value domain 1..3.
     * - PageNumber: SHORT[2], pageIndex < totalPages when totalPages != 0.
     * - Bit 2 (transparency mask) requires PhotometricInterpretation=4.
     *
     * @param bool $strictTiffNewSubfileType True to enforce TIFF-only bit constraints;
     *                                       false to allow extended DNG NewSubfileType values.
     */
    public function validateSubfileAndPageTags(Ifd $ifd, bool $strictTiffNewSubfileType): void
    {
        $newSubfileTypeEntry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);

        if ($newSubfileTypeEntry instanceof IfdEntry) {
            $this->validateNewSubfileTypeEntry($ifd, $newSubfileTypeEntry, $strictTiffNewSubfileType);
        }

        $subfileTypeEntry = $ifd->get(TiffTag::SUBFILE_TYPE);

        if ($subfileTypeEntry instanceof IfdEntry) {
            $this->validateSubfileTypeEntry($subfileTypeEntry);
        }

        $this->validateSubfileTypeConsistency(
            $newSubfileTypeEntry instanceof IfdEntry ? $newSubfileTypeEntry : null,
            $subfileTypeEntry instanceof IfdEntry ? $subfileTypeEntry : null,
            $strictTiffNewSubfileType,
        );

        $this->validatePageNumberEntry($ifd);
    }

    /**
     * Validates TIFF Threshholding / CellWidth / CellLength semantic coupling.
     *
     * TIFF 6.0:
     * - Threshholding: SHORT[1], value domain 1..3.
     * - CellWidth/CellLength: SHORT[1], >0.
     * - CellWidth/CellLength are valid only when Threshholding=2.
     * - Threshholding=2 requires both cell tags together.
     */
    public function validateThreshholdingAndCellTags(Ifd $ifd): void
    {
        $threshholdingEntry = $ifd->get(TiffTag::THRESHHOLDING);
        $cellWidthEntry     = $ifd->get(TiffTag::CELL_WIDTH);
        $cellLengthEntry    = $ifd->get(TiffTag::CELL_LENGTH);

        if ($threshholdingEntry instanceof IfdEntry) {
            if (
                ($threshholdingEntry->type !== TiffConst::TYPE_SHORT)
                || ($threshholdingEntry->count !== 1)
                || !is_int($threshholdingEntry->value)
            ) {
                throw new ParseError('Threshholding must be SHORT[1].', 1798);
            }

            if (($threshholdingEntry->value < 1) || ($threshholdingEntry->value > 3)) {
                throw new ParseError(
                    sprintf(
                        'Threshholding value %d is invalid; allowed values are 1,2,3.',
                        $threshholdingEntry->value,
                    ),
                    1799,
                );
            }
        }

        $hasCellWidth  = $cellWidthEntry instanceof IfdEntry;
        $hasCellLength = $cellLengthEntry instanceof IfdEntry;

        if ($hasCellWidth) {
            if (($cellWidthEntry->type !== TiffConst::TYPE_SHORT) || ($cellWidthEntry->count !== 1) || !is_int($cellWidthEntry->value)) {
                throw new ParseError('CellWidth must be SHORT[1].', 1800);
            }

            if ($cellWidthEntry->value <= 0) {
                throw new ParseError(sprintf('CellWidth must be > 0, got %d.', $cellWidthEntry->value), 1801);
            }
        }

        if ($hasCellLength) {
            if (($cellLengthEntry->type !== TiffConst::TYPE_SHORT) || ($cellLengthEntry->count !== 1) || !is_int($cellLengthEntry->value)) {
                throw new ParseError('CellLength must be SHORT[1].', 1802);
            }

            if ($cellLengthEntry->value <= 0) {
                throw new ParseError(sprintf('CellLength must be > 0, got %d.', $cellLengthEntry->value), 1803);
            }
        }

        $threshholdingValue = $threshholdingEntry instanceof IfdEntry
            ? $threshholdingEntry->value
            : null;

        if (($threshholdingValue === 2) && (!$hasCellWidth || !$hasCellLength)) {
            throw new ParseError('Threshholding=2 requires both CellWidth and CellLength.', 1804);
        }

        if (($hasCellWidth || $hasCellLength) && ($threshholdingValue !== 2)) {
            throw new ParseError(
                sprintf(
                    'CellWidth/CellLength are only valid when Threshholding=2, got %s.',
                    $threshholdingValue !== null ? (string) $threshholdingValue : 'missing',
                ),
                1805,
            );
        }
    }

    /**
     * Validates TIFF XPosition/YPosition semantic constraints.
     *
     * TIFF 6.0:
     * - XPosition/YPosition are RATIONAL[1].
     * - Rational denominator must be non-zero.
     * - YPosition must be strictly positive.
     */
    public function validatePositionTags(Ifd $ifd): void
    {
        $xPosition = $ifd->get(TiffTag::X_POSITION);
        $yPosition = $ifd->get(TiffTag::Y_POSITION);

        if (!($xPosition instanceof IfdEntry) && !($yPosition instanceof IfdEntry)) {
            return;
        }

        if ($xPosition instanceof IfdEntry) {
            $this->validatePositionRational($xPosition, 'XPosition');
        }

        if (!$yPosition instanceof IfdEntry) {
            return;
        }

        $yPositionRational = $this->validatePositionRational($yPosition, 'YPosition');
        $yPositionValue    = $yPositionRational->numerator / $yPositionRational->denominator;

        if ($yPositionValue <= 0.0) {
            throw new ParseError(
                sprintf('YPosition must be > 0, got %.6F.', $yPositionValue),
                1808,
            );
        }
    }

    /**
     * Validates paired TIFF free-space bookkeeping tags.
     *
     * TIFF 6.0 defines FreeOffsets (Tag 288) and FreeByteCounts (Tag 289) as a
     * paired map where each offset points to a free-byte range with a matching
     * positive byte-count entry.
     */
    public function validateFreeSpaceTags(Ifd $ifd): void
    {
        $freeOffsetsEntry    = $ifd->get(TiffTag::FREE_OFFSETS);
        $freeByteCountsEntry = $ifd->get(TiffTag::FREE_BYTE_COUNTS);

        if (!($freeOffsetsEntry instanceof IfdEntry) && !($freeByteCountsEntry instanceof IfdEntry)) {
            return;
        }

        if (!($freeOffsetsEntry instanceof IfdEntry) || !($freeByteCountsEntry instanceof IfdEntry)) {
            throw new ParseError('FreeOffsets and FreeByteCounts must both be present', 1809);
        }

        $freeOffsets    = $this->extractFreeSpaceComponents($freeOffsetsEntry, 'FreeOffsets');
        $freeByteCounts = $this->extractFreeSpaceComponents($freeByteCountsEntry, 'FreeByteCounts');

        if (count($freeOffsets) !== count($freeByteCounts)) {
            throw new ParseError(
                sprintf(
                    'FreeOffsets count %d must match FreeByteCounts count %d',
                    count($freeOffsets),
                    count($freeByteCounts),
                ),
                1810,
            );
        }

        $fileSize = $this->support->buffer()->size();

        foreach ($freeOffsets as $index => $offset) {
            $byteCount = $freeByteCounts[$index] ?? 0;

            if ($byteCount <= 0) {
                throw new ParseError(
                    sprintf('FreeByteCounts index %d must be > 0', $index),
                    1811,
                );
            }

            if (($offset > $fileSize) || ($offset > PHP_INT_MAX - $byteCount)) {
                throw new ParseError(
                    sprintf('Free-space range index %d exceeds TIFF data length', $index),
                    1812,
                );
            }

            if (($offset + $byteCount) > $fileSize) {
                throw new ParseError(
                    sprintf('Free-space range index %d exceeds TIFF data length', $index),
                    1813,
                );
            }
        }
    }

    /**
     * Validates Predictor semantic coupling to Compression.
     *
     * TIFF 6.0 Section 14 defines Predictor values {1,2} and describes horizontal
     * differencing (value 2) for LZW-compressed data.
     */
    public function validatePredictorTag(Ifd $ifd): void
    {
        $predictor = $ifd->get(TiffTag::PREDICTOR);
        if (!($predictor instanceof IfdEntry) || !is_int($predictor->value) || ($predictor->value !== 2)) {
            return;
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);
        if (($compression instanceof IfdEntry) && is_int($compression->value) && ($compression->value === Compression::Lzw->value)) {
            return;
        }

        throw new ParseError('Predictor=2 requires Compression=5 (LZW) per TIFF 6.0 Section 14.', 1825);
    }

    /**
     * Validates that ImageWidth and ImageLength tags exist with valid positive values.
     *
     * EXIF 3.0 §4.6.4 requires both tags in IFD0 for non-JPEG primary images.
     */
    public function validateImageDimensions(Ifd $ifd0): void
    {
        $widthEntry  = $ifd0->get(ExifTag::IMAGE_WIDTH);
        $lengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);

        // Postel's Law: EXIF 3.0 §4.6.4 requires ImageWidth/ImageLength in IFD0
        // for non-JPEG primary images, but RAW formats (NEF, CR2, ARW, etc.) use
        // SubIFD-based layouts where IFD0 holds only metadata and a thumbnail.
        // Also, IFD0 with NewSubFileType != 0 indicates a non-primary image.
        // Skip the dimension check when tags are absent — the image dimensions
        // are not needed for metadata extraction.  (GH-1548)
        if (!$widthEntry instanceof IfdEntry || !$lengthEntry instanceof IfdEntry) {
            return;
        }

        if (is_int($widthEntry->value) && $widthEntry->value <= 0) {
            throw new ParseError(sprintf(
                'ImageWidth value %d is invalid; must be a positive integer per EXIF 3.0 §4.6.4.',
                $widthEntry->value,
            ), 1355);
        }

        if (is_int($lengthEntry->value) && $lengthEntry->value <= 0) {
            throw new ParseError(sprintf(
                'ImageLength value %d is invalid; must be a positive integer per EXIF 3.0 §4.6.4.',
                $lengthEntry->value,
            ), 1356);
        }
    }

    /**
     * Validates NewSubfileType field type, bit constraints and transparency-mask semantics.
     *
     * TIFF 6.0:
     * - NewSubfileType: LONG[1] bitfield (bits 0..2 only in baseline TIFF).
     * - Bit 2 (transparency mask) requires PhotometricInterpretation=4.
     */
    private function validateNewSubfileTypeEntry(
        Ifd $ifd,
        IfdEntry $newSubfileTypeEntry,
        bool $strictTiffNewSubfileType,
    ): void {
        if (
            ($newSubfileTypeEntry->type !== TiffConst::TYPE_LONG)
            || ($newSubfileTypeEntry->count !== 1)
            || !is_int($newSubfileTypeEntry->value)
        ) {
            throw new ParseError('NewSubfileType must be LONG[1].', 1788);
        }

        $isDngExtendedNewSubfileType = in_array($newSubfileTypeEntry->value, [8, 9, 16, 65540], true);

        if (
            $strictTiffNewSubfileType
            && !$isDngExtendedNewSubfileType
            && (($newSubfileTypeEntry->value & ~0b111) !== 0)
        ) {
            throw new ParseError(
                sprintf(
                    'NewSubfileType value %d contains reserved bits outside 0..2.',
                    $newSubfileTypeEntry->value,
                ),
                1789,
            );
        }

        if (
            $strictTiffNewSubfileType
            && !$isDngExtendedNewSubfileType
            && (($newSubfileTypeEntry->value & 0b100) !== 0)
        ) {
            $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
            $photometricCode  = (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value))
                ? $photometricEntry->value
                : null;

            if ($photometricCode !== 4) {
                throw new ParseError(
                    sprintf(
                        'NewSubfileType transparency-mask bit requires PhotometricInterpretation=4, got %s.',
                        $photometricCode !== null ? (string) $photometricCode : 'missing',
                    ),
                    1790,
                );
            }
        }
    }

    /**
     * Validates SubfileType (deprecated) field type and value domain.
     *
     * TIFF 6.0:
     * - SubfileType (deprecated): SHORT[1], value domain 1..3.
     */
    private function validateSubfileTypeEntry(IfdEntry $subfileTypeEntry): void
    {
        if (
            ($subfileTypeEntry->type !== TiffConst::TYPE_SHORT)
            || ($subfileTypeEntry->count !== 1)
            || !is_int($subfileTypeEntry->value)
        ) {
            throw new ParseError('SubfileType must be SHORT[1].', 1791);
        }

        if (($subfileTypeEntry->value < 1) || ($subfileTypeEntry->value > 3)) {
            throw new ParseError(
                sprintf(
                    'SubfileType value %d is invalid; allowed values are 1..3.',
                    $subfileTypeEntry->value,
                ),
                1792,
            );
        }
    }

    /**
     * Validates NewSubfileType and SubfileType low-bit consistency.
     *
     * TIFF 6.0:
     * - When both NewSubfileType and SubfileType are present, the low two bits
     *   of NewSubfileType must equal (SubfileType - 1).
     */
    private function validateSubfileTypeConsistency(
        ?IfdEntry $newSubfileTypeEntry,
        ?IfdEntry $subfileTypeEntry,
        bool $strictTiffNewSubfileType,
    ): void {
        if (
            !$strictTiffNewSubfileType
            || (!$newSubfileTypeEntry instanceof IfdEntry)
            || (!$subfileTypeEntry instanceof IfdEntry)
            || !is_int($newSubfileTypeEntry->value)
            || !is_int($subfileTypeEntry->value)
            || in_array($newSubfileTypeEntry->value, [8, 9, 16, 65540], true)
        ) {
            return;
        }

        $expectedNewSubfileTypeLowBits = $subfileTypeEntry->value - 1;
        $actualNewSubfileTypeLowBits   = $newSubfileTypeEntry->value & 0b11;

        if ($actualNewSubfileTypeLowBits !== $expectedNewSubfileTypeLowBits) {
            throw new ParseError(
                sprintf(
                    'SubfileType %d conflicts with NewSubfileType %d.',
                    $subfileTypeEntry->value,
                    $newSubfileTypeEntry->value,
                ),
                1793,
            );
        }
    }

    /**
     * Validates PageNumber field type, component count and index/total semantics.
     *
     * TIFF 6.0:
     * - PageNumber: SHORT[2], pageIndex < totalPages when totalPages != 0.
     */
    private function validatePageNumberEntry(Ifd $ifd): void
    {
        $pageNumberEntry = $ifd->get(TiffTag::PAGE_NUMBER);

        if (!$pageNumberEntry instanceof IfdEntry) {
            return;
        }

        if (($pageNumberEntry->type !== TiffConst::TYPE_SHORT) || ($pageNumberEntry->count !== 2)) {
            throw new ParseError('PageNumber must be SHORT[2].', 1794);
        }

        $pageComponents = $this->support->extractIntegerTagComponents($pageNumberEntry, 'PageNumber');

        if (count($pageComponents) !== 2) {
            throw new ParseError(
                sprintf('PageNumber expected 2 components, decoded %d.', count($pageComponents)),
                1795,
            );
        }

        $pageIndex  = $pageComponents[0];
        $totalPages = $pageComponents[1];

        if ($pageIndex < 0) {
            throw new ParseError(
                sprintf('PageNumber page index must be >= 0, got %d.', $pageIndex),
                1796,
            );
        }

        if (($totalPages !== 0) && ($pageIndex >= $totalPages)) {
            throw new ParseError(
                sprintf(
                    'PageNumber index %d must be less than total pages %d when total is known.',
                    $pageIndex,
                    $totalPages,
                ),
                1797,
            );
        }
    }

    /**
     * Validates a position tag as RATIONAL[1] with non-zero denominator.
     */
    private function validatePositionRational(IfdEntry $entry, string $tagName): ExifRational
    {
        if (
            ($entry->type !== TiffConst::TYPE_RATIONAL)
            || ($entry->count !== 1)
            || !($entry->value instanceof ExifRational)
        ) {
            throw new ParseError(
                sprintf('%s must be RATIONAL[1].', $tagName),
                1806,
            );
        }

        if ($entry->value->denominator === 0) {
            throw new ParseError(
                sprintf('%s denominator must be non-zero.', $tagName),
                1807,
            );
        }

        return $entry->value;
    }

    /**
     * Extracts validated integer components for a free-space bookkeeping tag.
     *
     * @return list<int>
     */
    private function extractFreeSpaceComponents(IfdEntry $entry, string $tagName): array
    {
        if ($entry->type !== TiffConst::TYPE_LONG && $entry->type !== TiffConst::TYPE_LONG8) {
            throw new ParseError(
                sprintf('%s must use LONG/LONG8 type.', $tagName),
                1814,
            );
        }

        if ($entry->count < 1) {
            throw new ParseError(
                sprintf('%s must contain at least one value.', $tagName),
                1815,
            );
        }

        $components = $this->support->extractIntegerTagComponents($entry, $tagName);

        if (count($components) !== $entry->count) {
            throw new ParseError(
                sprintf('%s value count does not match declared component count.', $tagName),
                1816,
            );
        }

        foreach ($components as $index => $component) {
            if ($component >= 0) {
                continue;
            }

            throw new ParseError(
                sprintf('%s index %d must be >= 0', $tagName, $index),
                1817,
            );
        }

        return $components;
    }
}
