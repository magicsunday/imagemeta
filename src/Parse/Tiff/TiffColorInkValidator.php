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

use function array_fill;
use function count;
use function explode;
use function in_array;
use function intdiv;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Validates TIFF 6.0 color, ink, and transfer-function constraints on IFD entries.
 *
 * Covers separated image ink/dot-range semantics, transfer-function family tags,
 * and palette ColorMap validation as defined by TIFF 6.0 §6 and §16.
 */
final readonly class TiffColorInkValidator
{
    public function __construct(
        private TiffValidationSupport $support,
    ) {
    }

    public function validateSeparatedImageInkTags(Ifd $ifd): void
    {
        if (!$this->requireSeparatedPhotometric($ifd)) {
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
            throw new ParseError('InkNames must use ASCII field type.', 1940);
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

        if (!$this->requireSeparatedPhotometric($ifd)) {
            return;
        }

        $samplesPerPixel = $this->validateDotRangeTypeAndCount($ifd, $dotRangeEntry);
        $dotRangeValues  = $this->extractDotRangeValues($dotRangeEntry);
        $bitDepths       = $this->extractDotRangeBitDepths($ifd, $samplesPerPixel);

        $this->validateDotRangePairs($dotRangeEntry->count, $dotRangeValues, $bitDepths);
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

            // Postel's Law: TransferFunction requires BitsPerSample for count
            // validation, but real-world cameras (e.g. FujiFilm DS-7/DS-10) omit
            // BitsPerSample.  Skip the count check when the companion is absent.
            $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

            if ($bitsEntry instanceof IfdEntry) {
                $bitsPerSample = $this->support->resolveUniformBitsPerSample($ifd, 'TransferFunction', 1736);
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

            $bitsPerSample = $this->support->resolveUniformBitsPerSample($ifd, 'ColorMap', 1746);
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
     * Validates TargetPrinter context and requires PhotometricInterpretation=5 (Separated).
     *
     * Returns true when the IFD uses Separated photometric mode, false when the
     * photometric mode is absent or non-Separated. Throws if TargetPrinter is present
     * but photometric mode is not Separated.
     */
    private function requireSeparatedPhotometric(Ifd $ifd): bool
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

        return ($photometric instanceof IfdEntry) && is_int($photometric->value) && ($photometric->value === 5);
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
            throw new ParseError('DotRange values must decode to integers.', 1941);
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
            throw new ParseError('BitsPerSample must decode to integer components.', 1942);
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
}
