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

use function count;
use function in_array;
use function is_int;
use function sprintf;

/**
 * Validates TIFF 6.0 sample-related tag constraints.
 *
 * Covers MinSampleValue/MaxSampleValue, SampleFormat/SMin/SMax domain tags,
 * ExtraSamples, GrayResponse tags, and HalftoneHints.
 */
final readonly class TiffSampleValidator
{
    public function __construct(
        private TiffValidationSupport $support,
    ) {
    }

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

            $minSampleValues = $this->support->extractIntegerTagComponents($minSampleValueEntry, 'MinSampleValue');
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

            $maxSampleValues = $this->support->extractIntegerTagComponents($maxSampleValueEntry, 'MaxSampleValue');
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

        $bitsPerSampleValues = $this->support->extractIntegerTagComponents($bitsPerSampleEntry, 'BitsPerSample');
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

        $sampleFormats = $this->support->extractIntegerTagComponents($sampleFormatEntry, 'SampleFormat');

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

        return $this->support->extractNumericTagComponents($entry, $tagName);
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

        if (!in_array($extraSamplesEntry->value, [0, 1, 2], true)) {
            throw new ParseError(
                sprintf(
                    'ExtraSamples value %d is outside the valid domain {0, 1, 2}.',
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

        $bitsPerSample = $this->support->resolveUniformBitsPerSample($ifd, 'GrayResponseCurve', 1773);
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

        $components = $this->support->extractIntegerTagComponents($halftoneHintsEntry, 'HalftoneHints');

        if (count($components) !== 2) {
            throw new ParseError(
                sprintf('HalftoneHints expected 2 components, decoded %d.', count($components)),
                1780,
            );
        }

        $bitsPerSample = $this->support->resolveUniformBitsPerSample($ifd, 'HalftoneHints', 1782);
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
}
