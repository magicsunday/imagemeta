<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;

use function array_fill;
use function count;
use function explode;
use function in_array;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Validates TIFF 6.0 structural and semantic constraints on IFD entries.
 *
 * TIFF 6.0 defines baseline directory semantics, field type rules, and cross-tag
 * dependencies validated by this class. Methods are grouped by the TIFF 6.0 section
 * that specifies the relevant constraint.
 */
final readonly class TiffStructuralValidator
{
    public function __construct(
        private MemoryBuffer $buffer,
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
            throw new ParseError('EnhanceParams must not be empty for an Enhanced IFD per DNG 1.5.', 1324);
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

        $pageComponents = $this->extractIntegerTagComponents($pageNumberEntry, 'PageNumber');

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

        $fileSize = $this->buffer->size();

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

        $components = $this->extractIntegerTagComponents($entry, $tagName);

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

    /**
     * Validates TIFF MinSampleValue/MaxSampleValue structure and component ranges.
     *
     * TIFF 6.0 defines MinSampleValue/MaxSampleValue as SHORT vectors whose count
     * matches SamplesPerPixel and whose values are constrained by BitsPerSample.
     */
    public function validateMinMaxSampleValueTags(Ifd $ifd): void
    {
        $minSampleValueEntry = $ifd->get(TiffTag::MIN_SAMPLE_VALUE);
        $maxSampleValueEntry = $ifd->get(TiffTag::MAX_SAMPLE_VALUE);

        if (!($minSampleValueEntry instanceof IfdEntry) && !($maxSampleValueEntry instanceof IfdEntry)) {
            return;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if (($samplesEntry instanceof IfdEntry) && is_int($samplesEntry->value) && ($samplesEntry->value > 0)) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $minSampleValues = null;
        $maxSampleValues = null;

        if ($minSampleValueEntry instanceof IfdEntry) {
            if ($minSampleValueEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError('MinSampleValue must be SHORT.', 1818);
            }

            if ($minSampleValueEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'MinSampleValue count %d must match SamplesPerPixel %d.',
                        $minSampleValueEntry->count,
                        $samplesPerPixel,
                    ),
                    1819,
                );
            }

            $minSampleValues = $this->extractIntegerTagComponents($minSampleValueEntry, 'MinSampleValue');
            $this->validateMinMaxValueRangeAgainstBitsPerSample($ifd, 'MinSampleValue', $minSampleValues);
        }

        if ($maxSampleValueEntry instanceof IfdEntry) {
            if ($maxSampleValueEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError('MaxSampleValue must be SHORT.', 1820);
            }

            if ($maxSampleValueEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'MaxSampleValue count %d must match SamplesPerPixel %d.',
                        $maxSampleValueEntry->count,
                        $samplesPerPixel,
                    ),
                    1821,
                );
            }

            $maxSampleValues = $this->extractIntegerTagComponents($maxSampleValueEntry, 'MaxSampleValue');
            $this->validateMinMaxValueRangeAgainstBitsPerSample($ifd, 'MaxSampleValue', $maxSampleValues);
        }

        if (($minSampleValues === null) || ($maxSampleValues === null)) {
            return;
        }

        foreach ($minSampleValues as $componentIndex => $minSampleValue) {
            $maxSampleValue = $maxSampleValues[$componentIndex] ?? null;
            if ($maxSampleValue === null) {
                continue;
            }

            if ($minSampleValue <= $maxSampleValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'MinSampleValue component %d must be <= MaxSampleValue component %d.',
                    $componentIndex,
                    $componentIndex,
                ),
                1822,
            );
        }
    }

    /**
     * Validates MinSampleValue/MaxSampleValue components against BitsPerSample domain.
     *
     * @param list<int> $values
     */
    private function validateMinMaxValueRangeAgainstBitsPerSample(Ifd $ifd, string $tagName, array $values): void
    {
        $bitsPerSampleEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);
        if (!$bitsPerSampleEntry instanceof IfdEntry || ($bitsPerSampleEntry->type !== TiffConst::TYPE_SHORT)) {
            return;
        }

        $bitsPerSampleValues = $this->extractIntegerTagComponents($bitsPerSampleEntry, 'BitsPerSample');
        if ($bitsPerSampleValues === []) {
            return;
        }

        foreach ($values as $componentIndex => $value) {
            $bitsPerSample = $bitsPerSampleValues[0];
            if (count($bitsPerSampleValues) > 1) {
                if (!isset($bitsPerSampleValues[$componentIndex])) {
                    continue;
                }

                $bitsPerSample = $bitsPerSampleValues[$componentIndex];
            }

            if ($bitsPerSample >= 16) {
                continue;
            }

            if ($bitsPerSample <= 0) {
                throw new ParseError(
                    sprintf(
                        'BitsPerSample component %d must be > 0 when validating %s.',
                        $componentIndex,
                        $tagName,
                    ),
                    1823,
                );
            }

            $maxValue = (1 << $bitsPerSample) - 1;
            if ($value <= $maxValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s component %d value %d exceeds %d-bit range 0..%d.',
                    $tagName,
                    $componentIndex,
                    $value,
                    $bitsPerSample,
                    $maxValue,
                ),
                1824,
            );
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
        if (($compression instanceof IfdEntry) && is_int($compression->value) && ($compression->value === Compression::LZW->value)) {
            return;
        }

        throw new ParseError('Predictor=2 requires Compression=5 (LZW) per TIFF 6.0 Section 14.', 1825);
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
            && ($compression->value === Compression::JPEG->value);

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
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        $jpegProc      = (($jpegProcEntry instanceof IfdEntry) && is_int($jpegProcEntry->value))
            ? $jpegProcEntry->value
            : null;

        $samplesPerPixelEntry = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        $samplesPerPixel      = 1;

        if (($samplesPerPixelEntry instanceof IfdEntry) && is_int($samplesPerPixelEntry->value) && ($samplesPerPixelEntry->value > 0)) {
            $samplesPerPixel = $samplesPerPixelEntry->value;
        }

        $losslessPredictorsEntry = $ifd->get(TiffTag::JPEG_LOSSLESS_PREDICTORS);
        if ($losslessPredictorsEntry instanceof IfdEntry) {
            if (
                ($losslessPredictorsEntry->type !== TiffConst::TYPE_SHORT)
                || ($losslessPredictorsEntry->count !== $samplesPerPixel)
            ) {
                throw new ParseError('JPEGLosslessPredictors must be SHORT[SamplesPerPixel].', 1836);
            }

            $predictorValues = $this->extractIntegerTagComponents($losslessPredictorsEntry, 'JPEGLosslessPredictors');
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

            $this->extractIntegerTagComponents($pointTransformsEntry, 'JPEGPointTransforms');
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
            && ($compressionEntry->value === Compression::JPEG->value);

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
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        $jpegProc      = (($jpegProcEntry instanceof IfdEntry) && is_int($jpegProcEntry->value))
            ? $jpegProcEntry->value
            : null;

        $samplesPerPixelEntry = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        $samplesPerPixel      = 1;

        if (($samplesPerPixelEntry instanceof IfdEntry) && is_int($samplesPerPixelEntry->value) && ($samplesPerPixelEntry->value > 0)) {
            $samplesPerPixel = $samplesPerPixelEntry->value;
        }

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
     * Validates that all JPEG table offsets point inside the TIFF blob.
     *
     * TIFF 6.0 Section 22 uses LONG offsets for JPEG table pointers.
     */
    private function validateJpegTableOffsets(IfdEntry $entry, string $tagName): void
    {
        $tableOffsets = $this->extractIntegerTagComponents($entry, $tagName);
        $blobSize     = $this->buffer->size();

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
            throw new ParseError(
                'JPEGInterchangeFormatLength requires JPEGInterchangeFormat.',
                1830,
            );
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

        if (!($lengthEntry instanceof IfdEntry) || !is_int($lengthEntry->value)) {
            throw new ParseError(
                'Non-zero JPEGInterchangeFormat requires JPEGInterchangeFormatLength.',
                1833,
            );
        }

        if ($lengthEntry->value <= 0) {
            throw new ParseError(
                'JPEGInterchangeFormatLength must be > 0 when JPEGInterchangeFormat is non-zero.',
                1834,
            );
        }

        $blobSize = $this->buffer->size();
        if (
            ($offsetEntry->value > $blobSize)
            || ($lengthEntry->value > $blobSize)
            || ($offsetEntry->value > ($blobSize - $lengthEntry->value))
        ) {
            throw new ParseError('JPEGInterchangeFormat range exceeds TIFF data length.', 1835);
        }
    }

    /**
     * Validates TIFF SampleFormat / SMinSampleValue / SMaxSampleValue consistency.
     *
     * TIFF 6.0 §19:
     * - SampleFormat: SHORT[SamplesPerPixel], values {1,2,3,4}.
     * - SMinSampleValue/SMaxSampleValue: count = SamplesPerPixel.
     * - SMin/SMax types should match the declared sample representation.
     * - Per component, SMin must not exceed SMax.
     */
    public function validateSampleDomainTags(Ifd $ifd): void
    {
        $sampleFormatEntry = $ifd->get(TiffTag::SAMPLE_FORMAT);
        $sMinEntry         = $ifd->get(TiffTag::S_MIN_SAMPLE_VALUE);
        $sMaxEntry         = $ifd->get(TiffTag::S_MAX_SAMPLE_VALUE);

        if (
            !($sampleFormatEntry instanceof IfdEntry)
            && !($sMinEntry instanceof IfdEntry)
            && !($sMaxEntry instanceof IfdEntry)
        ) {
            return;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if (($samplesEntry instanceof IfdEntry) && is_int($samplesEntry->value) && ($samplesEntry->value > 0)) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $sampleFormats = ($sampleFormatEntry instanceof IfdEntry)
            ? $this->validateSampleFormatEntry($sampleFormatEntry, $samplesPerPixel)
            : null;

        $sMinValues = ($sMinEntry instanceof IfdEntry)
            ? $this->validateSampleBoundEntry($sMinEntry, 'SMinSampleValue', $samplesPerPixel, 1759)
            : null;

        $sMaxValues = ($sMaxEntry instanceof IfdEntry)
            ? $this->validateSampleBoundEntry($sMaxEntry, 'SMaxSampleValue', $samplesPerPixel, 1760)
            : null;

        $this->validateSampleDomainCrossConstraints(
            $sampleFormats,
            $sMinEntry instanceof IfdEntry ? $sMinEntry : null,
            $sMaxEntry instanceof IfdEntry ? $sMaxEntry : null,
            $sMinValues,
            $sMaxValues,
        );
    }

    /**
     * Validates SampleFormat field type, count against SamplesPerPixel and value domain.
     *
     * TIFF 6.0 §19:
     * - SampleFormat: SHORT[SamplesPerPixel], values {1,2,3,4}.
     *
     * @return list<int>
     */
    private function validateSampleFormatEntry(IfdEntry $sampleFormatEntry, int $samplesPerPixel): array
    {
        if ($sampleFormatEntry->type !== TiffConst::TYPE_SHORT) {
            throw new ParseError('SampleFormat must use SHORT type.', 1756);
        }

        if ($sampleFormatEntry->count !== $samplesPerPixel) {
            throw new ParseError(
                sprintf(
                    'SampleFormat count %d must match SamplesPerPixel %d.',
                    $sampleFormatEntry->count,
                    $samplesPerPixel,
                ),
                1757,
            );
        }

        $sampleFormats = $this->extractIntegerTagComponents($sampleFormatEntry, 'SampleFormat');

        foreach ($sampleFormats as $componentIndex => $sampleFormat) {
            if (!in_array($sampleFormat, [1, 2, 3, 4], true)) {
                throw new ParseError(
                    sprintf(
                        'SampleFormat component %d value %d is invalid; allowed values are 1,2,3,4.',
                        $componentIndex,
                        $sampleFormat,
                    ),
                    1758,
                );
            }
        }

        return $sampleFormats;
    }

    /**
     * Validates SMinSampleValue or SMaxSampleValue count and extracts numeric components.
     *
     * TIFF 6.0 §19:
     * - SMinSampleValue/SMaxSampleValue: count = SamplesPerPixel.
     *
     * @return list<int|float>
     */
    private function validateSampleBoundEntry(
        IfdEntry $entry,
        string $tagName,
        int $samplesPerPixel,
        int $errorCode,
    ): array {
        if ($entry->count !== $samplesPerPixel) {
            throw new ParseError(
                sprintf(
                    '%s count %d must match SamplesPerPixel %d.',
                    $tagName,
                    $entry->count,
                    $samplesPerPixel,
                ),
                $errorCode,
            );
        }

        return $this->extractNumericTagComponents($entry, $tagName);
    }

    /**
     * Cross-validates SampleFormat type compatibility and SMin <= SMax ordering.
     *
     * TIFF 6.0 §19:
     * - SMin/SMax types should match the declared sample representation.
     * - Per component, SMin must not exceed SMax.
     *
     * @param list<int>|null       $sampleFormats
     * @param list<int|float>|null $sMinValues
     * @param list<int|float>|null $sMaxValues
     */
    private function validateSampleDomainCrossConstraints(
        ?array $sampleFormats,
        ?IfdEntry $sMinEntry,
        ?IfdEntry $sMaxEntry,
        ?array $sMinValues,
        ?array $sMaxValues,
    ): void {
        if ($sampleFormats !== null && ($sMinEntry instanceof IfdEntry)) {
            $this->validateSampleDomainTypeCompatibility('SMinSampleValue', $sMinEntry->type, $sampleFormats);
        }

        if ($sampleFormats !== null && ($sMaxEntry instanceof IfdEntry)) {
            $this->validateSampleDomainTypeCompatibility('SMaxSampleValue', $sMaxEntry->type, $sampleFormats);
        }

        if (($sMinValues === null) || ($sMaxValues === null)) {
            return;
        }

        foreach ($sMinValues as $componentIndex => $sMinValue) {
            $sMaxValue = $sMaxValues[$componentIndex] ?? null;
            if ($sMaxValue === null) {
                continue;
            }

            if ($sMinValue <= $sMaxValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'SMinSampleValue component %d must be <= SMaxSampleValue, got %.6F > %.6F.',
                    $componentIndex,
                    $sMinValue,
                    $sMaxValue,
                ),
                1761,
            );
        }
    }

    /**
     * @param list<int> $sampleFormats
     */
    private function validateSampleDomainTypeCompatibility(string $tagName, int $tagType, array $sampleFormats): void
    {
        foreach ($sampleFormats as $componentIndex => $sampleFormat) {
            $compatible = match ($sampleFormat) {
                // Unsigned integer samples.
                1 => in_array($tagType, [TiffConst::TYPE_BYTE, TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_LONG8], true),
                // Signed integer samples.
                2 => in_array($tagType, [TiffConst::TYPE_SBYTE, TiffConst::TYPE_SSHORT, TiffConst::TYPE_SLONG, TiffConst::TYPE_SLONG8], true),
                // Floating-point samples.
                3 => in_array($tagType, [TiffConst::TYPE_FLOAT, TiffConst::TYPE_DOUBLE], true),
                // Undefined samples do not constrain min/max type.
                4       => true,
                default => false,
            };

            if ($compatible) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s type %d is incompatible with SampleFormat component %d value %d.',
                    $tagName,
                    $tagType,
                    $componentIndex,
                    $sampleFormat,
                ),
                1765,
            );
        }
    }

    /**
     * Validates TIFF 6.0 baseline ExtraSamples semantics.
     *
     * TIFF 6.0 baseline profile:
     * - ExtraSamples (Tag 338) must be SHORT[1]
     * - Value must be 1 (associated alpha)
     */
    public function validateExtraSamplesTag(Ifd $ifd): void
    {
        $extraSamplesEntry = $ifd->get(TiffTag::EXTRA_SAMPLES);

        if (!$extraSamplesEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($extraSamplesEntry->type !== TiffConst::TYPE_SHORT)
            || ($extraSamplesEntry->count !== 1)
            || !is_int($extraSamplesEntry->value)
        ) {
            throw new ParseError('ExtraSamples must be SHORT[1].', 1766);
        }

        if ($extraSamplesEntry->value !== 1) {
            throw new ParseError(
                sprintf(
                    'ExtraSamples value %d is invalid; strict TIFF 6.0 baseline requires value 1.',
                    $extraSamplesEntry->value,
                ),
                1767,
            );
        }
    }

    /**
     * Validates TIFF gray-response tags GrayResponseUnit and GrayResponseCurve.
     *
     * TIFF 6.0:
     * - GrayResponseUnit: SHORT[1], value domain 1..5.
     * - GrayResponseCurve: SHORT, count = 1 << BitsPerSample.
     * - Tags apply to grayscale photometric modes (WhiteIsZero/BlackIsZero).
     */
    public function validateGrayResponseTags(Ifd $ifd): void
    {
        $grayResponseUnit  = $ifd->get(TiffTag::GRAY_RESPONSE_UNIT);
        $grayResponseCurve = $ifd->get(TiffTag::GRAY_RESPONSE_CURVE);

        if (!($grayResponseUnit instanceof IfdEntry) && !($grayResponseCurve instanceof IfdEntry)) {
            return;
        }

        $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photometricCode  = (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value))
            ? $photometricEntry->value
            : null;

        if (!in_array($photometricCode, [0, 1], true)) {
            throw new ParseError(
                sprintf(
                    'GrayResponse tags are only valid for grayscale PhotometricInterpretation {0,1}, got %s.',
                    $photometricCode !== null ? (string) $photometricCode : 'missing',
                ),
                1768,
            );
        }

        if ($grayResponseUnit instanceof IfdEntry) {
            if (
                ($grayResponseUnit->type !== TiffConst::TYPE_SHORT)
                || ($grayResponseUnit->count !== 1)
                || !is_int($grayResponseUnit->value)
            ) {
                throw new ParseError('GrayResponseUnit must be SHORT[1].', 1769);
            }

            if (($grayResponseUnit->value < 1) || ($grayResponseUnit->value > 5)) {
                throw new ParseError(
                    sprintf(
                        'GrayResponseUnit value %d is outside the valid domain 1..5.',
                        $grayResponseUnit->value,
                    ),
                    1770,
                );
            }
        }

        if (!$grayResponseCurve instanceof IfdEntry) {
            return;
        }

        if ($grayResponseCurve->type !== TiffConst::TYPE_SHORT) {
            throw new ParseError(
                sprintf('GrayResponseCurve must use SHORT type, got type %d.', $grayResponseCurve->type),
                1771,
            );
        }

        $bitsPerSample = $this->resolveGrayResponseBitsPerSample($ifd);
        $expectedCount = 2 ** $bitsPerSample;

        if ($grayResponseCurve->count !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'GrayResponseCurve count %d must be 1<<BitsPerSample (%d).',
                    $grayResponseCurve->count,
                    $expectedCount,
                ),
                1772,
            );
        }
    }

    /**
     * Resolves a uniform BitsPerSample scalar for gray-response count rules.
     */
    private function resolveGrayResponseBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('GrayResponseCurve requires BitsPerSample.', 1773);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components for GrayResponseCurve.', 1774);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components for GrayResponseCurve.', 1774);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one value for GrayResponseCurve.', 1775);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for GrayResponseCurve.', $index),
                    1776,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'GrayResponseCurve requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1777,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('GrayResponseCurve does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1778,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates HalftoneHints value range against BitsPerSample.
     *
     * TIFF 6.0 §17:
     * - HalftoneHints is SHORT[2].
     * - Both hint values are gray codes within [0, (1<<BitsPerSample)-1].
     */
    public function validateHalftoneHintsTag(Ifd $ifd): void
    {
        $halftoneHintsEntry = $ifd->get(TiffTag::HALFTONE_HINTS);

        if (!$halftoneHintsEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($halftoneHintsEntry->type !== TiffConst::TYPE_SHORT)
            || ($halftoneHintsEntry->count !== 2)
        ) {
            throw new ParseError('HalftoneHints must be SHORT[2].', 1779);
        }

        $components = $this->extractIntegerTagComponents($halftoneHintsEntry, 'HalftoneHints');

        if (count($components) !== 2) {
            throw new ParseError(
                sprintf('HalftoneHints expected 2 components, decoded %d.', count($components)),
                1780,
            );
        }

        $bitsPerSample = $this->resolveHalftoneBitsPerSample($ifd);
        $maxValue      = (2 ** $bitsPerSample) - 1;

        foreach ($components as $componentIndex => $componentValue) {
            if (($componentValue >= 0) && ($componentValue <= $maxValue)) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'HalftoneHints component %d value %d exceeds max %d for BitsPerSample=%d.',
                    $componentIndex,
                    $componentValue,
                    $maxValue,
                    $bitsPerSample,
                ),
                1781,
            );
        }
    }

    /**
     * Resolves uniform BitsPerSample for HalftoneHints range checks.
     */
    private function resolveHalftoneBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('HalftoneHints validation requires BitsPerSample.', 1782);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components for HalftoneHints.', 1783);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components for HalftoneHints.', 1783);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one value for HalftoneHints.', 1784);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for HalftoneHints.', $index),
                    1785,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'HalftoneHints requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1786,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('HalftoneHints does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1787,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates TIFF separated-image ink tag semantics for PhotometricInterpretation=5.
     *
     * TIFF 6.0 separated images:
     * - InkSet: SHORT[1], domain {1,2}, default 1.
     * - NumberOfInks: SHORT[1], default 4.
     * - InkNames: ASCII NUL-separated list, count must match NumberOfInks.
     *
     * Cross-tag rules:
     * - InkSet=1 (CMYK): InkNames must not be present.
     * - InkSet=2: InkNames must be present and structurally valid.
     */
    public function validateSeparatedImageInkTags(Ifd $ifd): void
    {
        $photometric        = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $targetPrinterEntry = $ifd->get(TiffTag::TARGET_PRINTER);

        if (
            ($targetPrinterEntry instanceof IfdEntry)
            && ($photometric instanceof IfdEntry)
            && is_int($photometric->value)
            && ($photometric->value !== 5)
        ) {
            throw new ParseError(
                'TargetPrinter (tag 337) is only valid when PhotometricInterpretation=5 (Separated).',
                1721,
            );
        }

        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value) || ($photometric->value !== 5)) {
            return;
        }

        $inkSet      = 1;
        $inkSetEntry = $ifd->get(TiffTag::INK_SET);
        if ($inkSetEntry instanceof IfdEntry) {
            if (($inkSetEntry->type !== TiffConst::TYPE_SHORT) || ($inkSetEntry->count !== 1) || !is_int($inkSetEntry->value)) {
                throw new ParseError('InkSet must be SHORT[1] for separated images.', 1709);
            }

            $inkSet = $inkSetEntry->value;
        }

        if (($inkSet !== 1) && ($inkSet !== 2)) {
            throw new ParseError(
                sprintf('InkSet value %d is invalid; allowed values are 1 (CMYK) or 2 (not CMYK).', $inkSet),
                1710,
            );
        }

        $numberOfInks      = 4;
        $numberOfInksEntry = $ifd->get(TiffTag::NUMBER_OF_INKS);
        if ($numberOfInksEntry instanceof IfdEntry) {
            if (($numberOfInksEntry->type !== TiffConst::TYPE_SHORT) || ($numberOfInksEntry->count !== 1) || !is_int($numberOfInksEntry->value)) {
                throw new ParseError('NumberOfInks must be SHORT[1] when present.', 1711);
            }

            if ($numberOfInksEntry->value < 1) {
                throw new ParseError(
                    sprintf('NumberOfInks must be >= 1, got %d.', $numberOfInksEntry->value),
                    1712,
                );
            }

            $numberOfInks = $numberOfInksEntry->value;
        }

        $inkNamesEntry = $ifd->get(TiffTag::INK_NAMES);
        if ($inkSet === 1) {
            if ($inkNamesEntry instanceof IfdEntry) {
                throw new ParseError('InkNames must not be present when InkSet=1 (CMYK).', 1713);
            }

            return;
        }

        if (!($inkNamesEntry instanceof IfdEntry) || !is_string($inkNamesEntry->value)) {
            throw new ParseError('InkSet=2 requires an InkNames ASCII list.', 1714);
        }

        if ($inkNamesEntry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError('InkNames must use ASCII field type.', 1714);
        }

        $names = explode("\0", $inkNamesEntry->value);

        foreach ($names as $index => $name) {
            if ($name === '') {
                throw new ParseError(
                    sprintf('InkNames contains an empty name entry at position %d.', $index),
                    1715,
                );
            }
        }

        if (count($names) !== $numberOfInks) {
            throw new ParseError(
                sprintf('InkNames string count %d must match NumberOfInks %d.', count($names), $numberOfInks),
                1716,
            );
        }
    }

    /**
     * Validates TIFF DotRange semantics for separated images.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Type must be BYTE or SHORT.
     * - Count must be 2 or 2*SamplesPerPixel.
     * - Values are (black, white) pairs with black < white.
     * - Values must be within [0, (2^BitsPerSample)-1].
     */
    public function validateSeparatedImageDotRange(Ifd $ifd): void
    {
        $dotRangeEntry = $ifd->get(TiffTag::DOT_RANGE);

        if (!$dotRangeEntry instanceof IfdEntry) {
            return;
        }

        $photometric        = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $targetPrinterEntry = $ifd->get(TiffTag::TARGET_PRINTER);

        if (
            ($targetPrinterEntry instanceof IfdEntry)
            && ($photometric instanceof IfdEntry)
            && is_int($photometric->value)
            && ($photometric->value !== 5)
        ) {
            throw new ParseError(
                'TargetPrinter (tag 337) is only valid when PhotometricInterpretation=5 (Separated).',
                1721,
            );
        }

        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value) || ($photometric->value !== 5)) {
            return;
        }

        $samplesPerPixel = $this->validateDotRangeTypeAndCount($ifd, $dotRangeEntry);
        $dotRangeValues  = $this->extractDotRangeValues($dotRangeEntry);
        $bitDepths       = $this->extractDotRangeBitDepths($ifd, $samplesPerPixel);

        $this->validateDotRangePairs($dotRangeEntry->count, $dotRangeValues, $bitDepths);
    }

    /**
     * Validates DotRange field type, resolves SamplesPerPixel and checks count.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Type must be BYTE or SHORT.
     * - Count must be 2 or 2*SamplesPerPixel.
     */
    private function validateDotRangeTypeAndCount(Ifd $ifd, IfdEntry $dotRangeEntry): int
    {
        if (($dotRangeEntry->type !== TiffConst::TYPE_BYTE) && ($dotRangeEntry->type !== TiffConst::TYPE_SHORT)) {
            throw new ParseError(
                sprintf(
                    'DotRange (tag 336) expects type BYTE or SHORT, got type %d.',
                    $dotRangeEntry->type,
                ),
                1717,
            );
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if ($samplesEntry instanceof IfdEntry) {
            if (!is_int($samplesEntry->value) || ($samplesEntry->value <= 0)) {
                throw new ParseError('DotRange requires SamplesPerPixel as a positive integer.', 1718);
            }

            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedPerComponentCount = 2 * $samplesPerPixel;

        if (($dotRangeEntry->count !== 2) && ($dotRangeEntry->count !== $expectedPerComponentCount)) {
            throw new ParseError(
                sprintf(
                    'DotRange count %d must be 2 or 2*SamplesPerPixel (%d).',
                    $dotRangeEntry->count,
                    $expectedPerComponentCount,
                ),
                1719,
            );
        }

        return $samplesPerPixel;
    }

    /**
     * Extracts and validates integer DotRange values from the IFD entry payload.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Values are integer (black, white) pairs.
     *
     * @return list<int>
     */
    private function extractDotRangeValues(IfdEntry $dotRangeEntry): array
    {
        $dotRangeValues = [];

        if (is_int($dotRangeEntry->value)) {
            $dotRangeValues[] = $dotRangeEntry->value;
        } elseif ($dotRangeEntry->value instanceof ExifNumericList) {
            foreach ($dotRangeEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('DotRange values must decode to integers.', 1720);
                }

                $dotRangeValues[] = $component;
            }
        } else {
            throw new ParseError('DotRange values must decode to integers.', 1720);
        }

        if (count($dotRangeValues) !== $dotRangeEntry->count) {
            throw new ParseError(
                sprintf(
                    'DotRange expected %d values, decoded %d.',
                    $dotRangeEntry->count,
                    count($dotRangeValues),
                ),
                1721,
            );
        }

        return $dotRangeValues;
    }

    /**
     * Extracts BitsPerSample bit-depth array for DotRange bound checking.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Values must be within [0, (2^BitsPerSample)-1].
     *
     * @return list<int>
     */
    private function extractDotRangeBitDepths(Ifd $ifd, int $samplesPerPixel): array
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);
        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('DotRange validation requires BitsPerSample to be present.', 1722);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths = array_fill(0, $samplesPerPixel, $bitsEntry->value);
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components.', 1723);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components.', 1723);
        }

        if (count($bitDepths) === 1) {
            $bitDepths = array_fill(0, $samplesPerPixel, $bitDepths[0]);
        }

        if (count($bitDepths) !== $samplesPerPixel) {
            throw new ParseError(
                sprintf(
                    'BitsPerSample count %d must be 1 or SamplesPerPixel (%d) for DotRange checks.',
                    count($bitDepths),
                    $samplesPerPixel,
                ),
                1724,
            );
        }

        return $bitDepths;
    }

    /**
     * Validates DotRange (black, white) pairs against bit-depth bounds.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Values are (black, white) pairs with black < white.
     * - Values must be within [0, (2^BitsPerSample)-1].
     *
     * @param list<int> $dotRangeValues
     * @param list<int> $bitDepths
     */
    private function validateDotRangePairs(int $dotRangeCount, array $dotRangeValues, array $bitDepths): void
    {
        $pairCount = intdiv($dotRangeCount, 2);
        for ($pairIndex = 0; $pairIndex < $pairCount; ++$pairIndex) {
            $componentIndex = $dotRangeCount === 2 ? 0 : $pairIndex;
            $bitDepth       = $bitDepths[$componentIndex];

            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for DotRange validation.', $componentIndex),
                    1725,
                );
            }

            $this->validateDotRangePairBounds(
                $pairIndex,
                $dotRangeValues[$pairIndex * 2],
                $dotRangeValues[($pairIndex * 2) + 1],
                $bitDepth,
            );
        }
    }

    /**
     * Validates a single DotRange (black, white) pair ordering and bit-depth bounds.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Each pair must satisfy black < white.
     * - Both values must be within [0, (2^BitsPerSample)-1].
     */
    private function validateDotRangePairBounds(int $pairIndex, int $black, int $white, int $bitDepth): void
    {
        $maxValue = (2 ** $bitDepth) - 1;

        if ($black >= $white) {
            throw new ParseError(
                sprintf(
                    'DotRange pair index %d requires black < white, got %d >= %d.',
                    $pairIndex,
                    $black,
                    $white,
                ),
                1726,
            );
        }

        if (($black < 0) || ($black > $maxValue)) {
            throw new ParseError(
                sprintf(
                    'DotRange pair index %d black value %d exceeds max %d (BitsPerSample=%d).',
                    $pairIndex,
                    $black,
                    $maxValue,
                    $bitDepth,
                ),
                1727,
            );
        }

        if (($white < 0) || ($white > $maxValue)) {
            throw new ParseError(
                sprintf(
                    'DotRange pair index %d white value %d exceeds max %d (BitsPerSample=%d).',
                    $pairIndex,
                    $white,
                    $maxValue,
                    $bitDepth,
                ),
                1728,
            );
        }
    }

    /**
     * Validates TIFF transfer/range tag-family semantics.
     *
     * TIFF 6.0:
     * - TransferFunction (301): SHORT, count = {1 or 3} * (1 << BitsPerSample)
     *   and valid only for WhiteIsZero/BlackIsZero/RGB/Palette/YCbCr photometric modes.
     * - TransferRange (342): SHORT[6], valid only for RGB or YCbCr.
     * - ReferenceBlackWhite (532): RATIONAL[6], valid only for RGB or YCbCr.
     */
    public function validateTransferFamilyTags(Ifd $ifd): void
    {
        $transferFunction = $ifd->get(ExifTag::TRANSFER_FUNCTION);
        $transferRange    = $ifd->get(TiffTag::TRANSFER_RANGE);
        $referenceBw      = $ifd->get(ExifTag::REFERENCE_BLACK_WHITE);

        if (
            !($transferFunction instanceof IfdEntry)
            && !($transferRange instanceof IfdEntry)
            && !($referenceBw instanceof IfdEntry)
        ) {
            return;
        }

        $photometricValue = null;
        $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value)) {
            $photometricValue = $photometricEntry->value;
        }

        if ($transferFunction instanceof IfdEntry) {
            if ($transferFunction->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction must use SHORT type, got type %d.',
                        $transferFunction->type,
                    ),
                    1729,
                );
            }

            if (($photometricValue !== null) && !in_array($photometricValue, [0, 1, 2, 3, 6], true)) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction is only valid for PhotometricInterpretation {0,1,2,3,6}, got %s.',
                        (string) $photometricValue,
                    ),
                    1730,
                );
            }

            $bitsPerSample = $this->resolveTransferFunctionBitsPerSample($ifd);
            $tableCount    = 2 ** $bitsPerSample;

            if (($transferFunction->count !== $tableCount) && ($transferFunction->count !== (3 * $tableCount))) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction count %d must be %d or %d for BitsPerSample=%d.',
                        $transferFunction->count,
                        $tableCount,
                        3 * $tableCount,
                        $bitsPerSample,
                    ),
                    1731,
                );
            }
        }

        if ($transferRange instanceof IfdEntry) {
            if (($transferRange->type !== TiffConst::TYPE_SHORT) || ($transferRange->count !== 6)) {
                throw new ParseError('TransferRange must be SHORT[6].', 1732);
            }

            if (!in_array($photometricValue, [null, 2, 6], true)) {
                throw new ParseError(
                    sprintf(
                        'TransferRange is only valid for PhotometricInterpretation RGB(2) or YCbCr(6), got %s.',
                        (string) $photometricValue,
                    ),
                    1733,
                );
            }
        }

        if (!$referenceBw instanceof IfdEntry) {
            return;
        }

        if (($referenceBw->type !== TiffConst::TYPE_RATIONAL) || ($referenceBw->count !== 6)) {
            throw new ParseError('ReferenceBlackWhite must be RATIONAL[6].', 1734);
        }

        if (!in_array($photometricValue, [null, 2, 6], true)) {
            throw new ParseError(
                sprintf(
                    'ReferenceBlackWhite is only valid for PhotometricInterpretation RGB(2) or YCbCr(6), got %s.',
                    (string) $photometricValue,
                ),
                1735,
            );
        }
    }

    /**
     * Resolves the uniform BitsPerSample scalar used by TransferFunction count rules.
     */
    private function resolveTransferFunctionBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('TransferFunction requires BitsPerSample.', 1736);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample components must be integers.', 1737);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample components must be integers.', 1737);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one component value.', 1738);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1.', $index),
                    1739,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1740,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('TransferFunction does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1741,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates TIFF ColorMap (Tag 320) palette applicability and count formula.
     *
     * TIFF 6.0 §6:
     * - ColorMap is required when PhotometricInterpretation = 3 (palette color).
     * - ColorMap type is SHORT.
     * - ColorMap count is 3 * (1 << BitsPerSample).
     * - ColorMap shall not be used for non-palette photometric modes.
     */
    public function validatePaletteColorMapTag(Ifd $ifd): void
    {
        $colorMapEntry   = $ifd->get(TiffTag::COLOR_MAP);
        $photometric     = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photometricCode = (($photometric instanceof IfdEntry) && is_int($photometric->value))
            ? $photometric->value
            : null;

        if ($photometricCode === 3) {
            if (!$colorMapEntry instanceof IfdEntry) {
                throw new ParseError('Palette images (PhotometricInterpretation=3) require ColorMap.', 1742);
            }

            if ($colorMapEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError(
                    sprintf('ColorMap must use SHORT type for palette images, got type %d.', $colorMapEntry->type),
                    1743,
                );
            }

            $bitsPerSample = $this->resolvePaletteColorMapBitsPerSample($ifd);
            $expectedCount = 3 * (2 ** $bitsPerSample);

            if ($colorMapEntry->count !== $expectedCount) {
                throw new ParseError(
                    sprintf(
                        'ColorMap count %d must be 3*(1<<BitsPerSample) = %d.',
                        $colorMapEntry->count,
                        $expectedCount,
                    ),
                    1744,
                );
            }

            return;
        }

        if (!$colorMapEntry instanceof IfdEntry) {
            return;
        }

        throw new ParseError(
            sprintf(
                'ColorMap is only valid for palette images (PhotometricInterpretation=3), got %s.',
                $photometricCode !== null ? (string) $photometricCode : 'missing',
            ),
            1745,
        );
    }

    /**
     * Resolves a uniform BitsPerSample scalar for ColorMap count validation.
     */
    private function resolvePaletteColorMapBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('ColorMap validation requires BitsPerSample.', 1746);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample components must be integers for ColorMap.', 1747);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample components must be integers for ColorMap.', 1747);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one component value.', 1748);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for ColorMap.', $index),
                    1749,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'ColorMap requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1750,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('ColorMap does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1751,
            );
        }

        return $uniformBitDepth;
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
        if (!$rowsPerStripEntry instanceof IfdEntry || !is_int($rowsPerStripEntry->value) || $rowsPerStripEntry->value <= 0) {
            throw new ParseError(
                'RowsPerStrip must be a positive integer when strip tags are present per EXIF 3.0 §4.6.5.2.2.',
                1452,
            );
        }

        $imageLengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);
        if (!$imageLengthEntry instanceof IfdEntry || !is_int($imageLengthEntry->value) || $imageLengthEntry->value <= 0) {
            return;
        }

        $stripsPerImage = intdiv($imageLengthEntry->value + $rowsPerStripEntry->value - 1, $rowsPerStripEntry->value);

        $planarConfiguration = 1;
        $planarEntry         = $ifd0->get(ExifTag::PLANAR_CONFIGURATION);
        if ($planarEntry instanceof IfdEntry && is_int($planarEntry->value)) {
            $planarConfiguration = $planarEntry->value;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd0->get(ExifTag::SAMPLES_PER_PIXEL);
        if ($samplesEntry instanceof IfdEntry && is_int($samplesEntry->value) && $samplesEntry->value > 0) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedCount = $stripsPerImage;
        if ($planarConfiguration === 2) {
            $expectedCount *= $samplesPerPixel;
        }

        if ($stripOffsetsEntry instanceof IfdEntry) {
            $offsetCount = $this->countStripFieldValues($stripOffsetsEntry);
            if ($offsetCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripOffsets count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.1/§4.6.5.2.2.',
                    $offsetCount,
                    $expectedCount,
                ), 1453);
            }
        }

        if ($stripByteCountsEntry instanceof IfdEntry) {
            $byteCountCount = $this->countStripFieldValues($stripByteCountsEntry);
            if ($byteCountCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripByteCounts count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.3/§4.6.5.2.2.',
                    $byteCountCount,
                    $expectedCount,
                ), 1454);
            }
        }

        if ($stripOffsetsEntry instanceof IfdEntry && $stripByteCountsEntry instanceof IfdEntry) {
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

        if (!$tileOffsetsEntry instanceof IfdEntry || !$tileByteCountsEntry instanceof IfdEntry) {
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
        if (
            !$tileWidthEntry instanceof IfdEntry
            || !is_int($tileWidthEntry->value)
            || ($tileWidthEntry->value <= 0)
        ) {
            throw new ParseError('TileWidth must be a positive integer when tiled layout tags are present.', 1695);
        }

        if (
            !$tileLengthEntry instanceof IfdEntry
            || !is_int($tileLengthEntry->value)
            || ($tileLengthEntry->value <= 0)
        ) {
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
        if (
            !$imageWidthEntry instanceof IfdEntry
            || !is_int($imageWidthEntry->value)
            || ($imageWidthEntry->value <= 0)
            || !$imageLengthEntry instanceof IfdEntry
            || !is_int($imageLengthEntry->value)
            || ($imageLengthEntry->value <= 0)
        ) {
            return;
        }

        $tilesAcross = intdiv($imageWidthEntry->value + $tileWidth - 1, $tileWidth);
        $tilesDown   = intdiv($imageLengthEntry->value + $tileLength - 1, $tileLength);

        $tilesPerImage = $tilesAcross * $tilesDown;

        $planarConfiguration = 1;
        $planarEntry         = $ifd0->get(ExifTag::PLANAR_CONFIGURATION);
        if ($planarEntry instanceof IfdEntry && is_int($planarEntry->value)) {
            $planarConfiguration = $planarEntry->value;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd0->get(ExifTag::SAMPLES_PER_PIXEL);
        if ($samplesEntry instanceof IfdEntry && is_int($samplesEntry->value) && $samplesEntry->value > 0) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedCount = $tilesPerImage;
        if ($planarConfiguration === 2) {
            $expectedCount *= $samplesPerPixel;
        }

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
                        $this->countedImageDataTagName($tag),
                        $index,
                    ), 1702);
                }

                $values[] = $component;
            }

            return $values;
        }

        throw new ParseError(sprintf(
            '%s has unsupported value representation for range validation.',
            $this->countedImageDataTagName($tag),
        ), 1702);
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
        $blobSize  = $this->buffer->size();
        $pairCount = count($offsets);

        for ($index = 0; $index < $pairCount; ++$index) {
            $offset    = $offsets[$index] ?? 0;
            $byteCount = $byteCounts[$index] ?? 0;

            if (
                ($offset < 0)
                || ($byteCount < 0)
                || ($offset > $blobSize)
                || ($byteCount > $blobSize)
                || ($offset > ($blobSize - $byteCount))
            ) {
                throw new ParseError(
                    sprintf(
                        '%s[%d]=%d with %s[%d]=%d exceeds TIFF data bounds (size=%d).',
                        $this->countedImageDataTagName($offsetTag),
                        $index,
                        $offset,
                        $this->countedImageDataTagName($byteCountTag),
                        $index,
                        $byteCount,
                        $blobSize,
                    ),
                    1702,
                );
            }
        }
    }

    /**
     * @return list<int>
     */
    private function extractIntegerTagComponents(IfdEntry $entry, string $tagName): array
    {
        $numericComponents = $this->extractNumericTagComponents($entry, $tagName);
        $integerComponents = [];

        foreach ($numericComponents as $componentIndex => $numericComponent) {
            if ((float) (int) $numericComponent !== $numericComponent) {
                throw new ParseError(
                    sprintf(
                        '%s component %d must be an integer, got %.6F.',
                        $tagName,
                        $componentIndex,
                        $numericComponent,
                    ),
                    1762,
                );
            }

            $integerComponents[] = (int) $numericComponent;
        }

        return $integerComponents;
    }

    /**
     * @return list<float>
     */
    private function extractNumericTagComponents(IfdEntry $entry, string $tagName): array
    {
        if (is_int($entry->value) || is_float($entry->value)) {
            return [(float) $entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $components = [];

            foreach ($entry->value->values as $component) {
                if (is_int($component) || is_float($component)) {
                    $components[] = (float) $component;
                    continue;
                }

                throw new ParseError(
                    sprintf('%s contains unsupported non-numeric component type.', $tagName),
                    1763,
                );
            }

            return $components;
        }

        throw new ParseError(
            sprintf('%s must decode to numeric components.', $tagName),
            1764,
        );
    }

    /**
     * Returns the canonical tag label for strip/tile counted image-data fields.
     */
    private function countedImageDataTagName(int $tag): string
    {
        return match ($tag) {
            ExifTag::STRIP_OFFSETS     => 'StripOffsets',
            ExifTag::STRIP_BYTE_COUNTS => 'StripByteCounts',
            TiffTag::TILE_OFFSETS      => 'TileOffsets',
            TiffTag::TILE_BYTE_COUNTS  => 'TileByteCounts',
            default                    => sprintf('IFD tag 0x%04X', $tag),
        };
    }
}
