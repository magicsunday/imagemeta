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
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Value\Enum\Photometric;

use function count;
use function in_array;
use function is_finite;
use function is_int;
use function sprintf;

/**
 * Validates DNG geometry, dimensions, levels, crop, and scale constraints.
 *
 * DNG 1.7.1.0 defines type/count rules, cross-tag dependencies, and geometric
 * layout constraints validated by this class.
 */
final readonly class DngGeometryValidator
{
    public function __construct(
        private DngValidationSupport $support,
    ) {
    }

    /**
     * Validates ActiveArea and MaskedAreas rectangle layout and geometry.
     *
     * DNG 1.7.1.0 ("ActiveArea", "MaskedAreas"):
     * - ActiveArea: SHORT|LONG[4], order top,left,bottom,right with top<bottom and left<right
     * - MaskedAreas: SHORT|LONG[4*N], each rectangle uses the same ordering and must not overlap
     */
    public function validateDngActiveAndMaskedAreas(Ifd $ifd): void
    {
        $activeArea = $ifd->get(DngTag::ACTIVE_AREA);
        if ($activeArea instanceof IfdEntry) {
            if (
                !in_array($activeArea->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
                || ($activeArea->count !== 4)
            ) {
                throw new ParseError(
                    sprintf(
                        'ActiveArea must be SHORT|LONG with count 4, got type %d count %d.',
                        $activeArea->type,
                        $activeArea->count,
                    ),
                    2072,
                );
            }

            $this->support->extractDngRectangles($activeArea, 'ActiveArea');
        }

        $maskedAreas = $ifd->get(DngTag::MASKED_AREAS);
        if ($maskedAreas instanceof IfdEntry) {
            if (
                !in_array($maskedAreas->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
                || ($maskedAreas->count < 4)
                || ($maskedAreas->count % 4 !== 0)
            ) {
                throw new ParseError(
                    sprintf(
                        'MaskedAreas must be SHORT|LONG with count 4*N, got type %d count %d.',
                        $maskedAreas->type,
                        $maskedAreas->count,
                    ),
                    2073,
                );
            }

            $rectangles = $this->support->extractDngRectangles($maskedAreas, 'MaskedAreas');

            $rectangleCount = count($rectangles);
            for ($leftIndex = 0; $leftIndex < $rectangleCount; ++$leftIndex) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $rectangleCount; ++$rightIndex) {
                    if ($this->support->dngRectanglesOverlap($rectangles[$leftIndex], $rectangles[$rightIndex])) {
                        throw new ParseError(
                            sprintf(
                                'MaskedAreas rectangles %d and %d overlap.',
                                $leftIndex,
                                $rightIndex,
                            ),
                            2074,
                        );
                    }
                }
            }
        }
    }

    /**
     * Validates cross-tag formulas for the DNG black/white-level tag family.
     *
     * DNG 1.7.1.0 ("BlackLevelRepeatDim", "BlackLevel", "BlackLevelDeltaH",
     * "BlackLevelDeltaV", "WhiteLevel") defines type/count constraints and
     * count formulas based on SamplesPerPixel and ActiveArea geometry.
     */
    public function validateDngBlackWhiteLevelFamily(Ifd $ifd): void
    {
        $samplesPerPixel = null;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if ($samplesEntry instanceof IfdEntry && is_int($samplesEntry->value) && $samplesEntry->value > 0) {
            $samplesPerPixel = $samplesEntry->value;
        }

        [$repeatRows, $repeatCols] = $this->validateDngBlackLevelRepeatDimAndLevel(
            $ifd,
            $samplesPerPixel,
        );

        [$activeWidth, $activeLength] = $this->resolveDngActiveAreaDimensions($ifd);

        $this->validateDngBlackLevelDeltas($ifd, $activeWidth, $activeLength);
        $this->validateDngWhiteLevel($ifd, $samplesPerPixel);
    }

    /**
     * Validates DefaultScale, DefaultCropOrigin and DefaultCropSize layout and geometry.
     *
     * DNG 1.7.1.0 ("DefaultScale", "DefaultCropOrigin", "DefaultCropSize"):
     * - DefaultScale: RATIONAL[2], both components > 0
     * - DefaultCropOrigin: SHORT|LONG|RATIONAL with count 2, components >= 0
     * - DefaultCropSize: SHORT|LONG|RATIONAL with count 2, components > 0
     */
    public function validateDngDefaultCropScaleGeometry(Ifd $ifd): void
    {
        $defaultScale = $ifd->get(DngTag::DEFAULT_SCALE);
        if ($defaultScale instanceof IfdEntry) {
            if (($defaultScale->type !== TiffConst::TYPE_RATIONAL) || ($defaultScale->count !== 2)) {
                throw new ParseError(
                    sprintf(
                        'DefaultScale must be RATIONAL[2], got type %d count %d.',
                        $defaultScale->type,
                        $defaultScale->count,
                    ),
                    1596,
                );
            }

            [$scaleH, $scaleV] = $this->support->extractDngCropScalePair($defaultScale, 'DefaultScale');
            if (($scaleH <= 0.0) || ($scaleV <= 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultScale components must be > 0, got (%.6F, %.6F).',
                        $scaleH,
                        $scaleV,
                    ),
                    1597,
                );
            }
        }

        $defaultCropOrigin = $ifd->get(DngTag::DEFAULT_CROP_ORIGIN);
        if ($defaultCropOrigin instanceof IfdEntry) {
            if (
                !in_array(
                    $defaultCropOrigin->type,
                    [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                    true,
                )
                || ($defaultCropOrigin->count !== 2)
            ) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropOrigin must be SHORT|LONG|RATIONAL with count 2, got type %d count %d.',
                        $defaultCropOrigin->type,
                        $defaultCropOrigin->count,
                    ),
                    1598,
                );
            }

            [$originH, $originV] = $this->support->extractDngCropScalePair($defaultCropOrigin, 'DefaultCropOrigin');
            if (($originH < 0.0) || ($originV < 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropOrigin components must be >= 0, got (%.6F, %.6F).',
                        $originH,
                        $originV,
                    ),
                    1599,
                );
            }
        }

        $defaultCropSize = $ifd->get(DngTag::DEFAULT_CROP_SIZE);
        if ($defaultCropSize instanceof IfdEntry) {
            if (
                !in_array(
                    $defaultCropSize->type,
                    [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                    true,
                )
                || ($defaultCropSize->count !== 2)
            ) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropSize must be SHORT|LONG|RATIONAL with count 2, got type %d count %d.',
                        $defaultCropSize->type,
                        $defaultCropSize->count,
                    ),
                    1600,
                );
            }

            [$sizeH, $sizeV] = $this->support->extractDngCropScalePair($defaultCropSize, 'DefaultCropSize');
            if (($sizeH <= 0.0) || ($sizeV <= 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropSize components must be > 0, got (%.6F, %.6F).',
                        $sizeH,
                        $sizeV,
                    ),
                    2067,
                );
            }
        }
    }

    /**
     * Validates DNG original proxy-size tags and their fallback semantics.
     *
     * DNG 1.7.1.0 ("OriginalDefaultFinalSize", "OriginalBestQualityFinalSize",
     * "OriginalDefaultCropSize") defines:
     * - OriginalDefaultFinalSize: SHORT|LONG[2], width/length > 0
     * - OriginalBestQualityFinalSize: SHORT|LONG[2], width/length > 0
     * - OriginalDefaultCropSize: SHORT|LONG|RATIONAL[2], width/length > 0
     *
     * Defaults:
     * - OriginalBestQualityFinalSize defaults to OriginalDefaultFinalSize if specified.
     * - OriginalDefaultCropSize defaults to OriginalDefaultFinalSize if specified.
     * - If OriginalDefaultFinalSize is absent, defaults continue to current-file values.
     */
    public function validateDngOriginalProxySizes(Ifd $ifd): void
    {
        $originalDefaultFinalSize = $this->support->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
            'OriginalDefaultFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalBestQualityFinalSize = $this->support->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
            'OriginalBestQualityFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalDefaultCropSize = $this->support->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
            'OriginalDefaultCropSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
        );

        // Fallback semantics: missing best-quality/crop size inherit from
        // OriginalDefaultFinalSize when it is explicitly present.
        if (($originalBestQualityFinalSize === null) && ($originalDefaultFinalSize !== null)) {
            $originalBestQualityFinalSize = $originalDefaultFinalSize;
        }

        if (($originalDefaultCropSize === null) && ($originalDefaultFinalSize !== null)) {
            $originalDefaultCropSize = $originalDefaultFinalSize;
        }

        // When OriginalDefaultFinalSize is absent, defaults are based on current-file
        // size tags; omission is valid and intentionally non-fatal.
    }

    /**
     * Validates BestQualityScale (0xC65C) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1] with a strictly positive numeric value.
     */
    public function validateDngBestQualityScale(Ifd $ifd): void
    {
        $scalar = $this->support->extractRationalScalar(
            $ifd,
            DngTag::BEST_QUALITY_SCALE,
            'BestQualityScale',
            TiffConst::TYPE_RATIONAL,
            'RATIONAL',
            1641,
            1642,
            1643,
            'be > 0',
        );

        if ($scalar === null) {
            return;
        }

        if ($scalar <= 0.0) {
            throw new ParseError('BestQualityScale value must be > 0.', 1644);
        }
    }

    /**
     * Validates LinearResponseLimit (0xC62E) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1] with fraction semantics: 0 < value <= 1.0.
     */
    public function validateDngLinearResponseLimit(Ifd $ifd): void
    {
        $limit = $this->support->extractRationalScalar(
            $ifd,
            DngTag::LINEAR_RESPONSE_LIMIT,
            'LinearResponseLimit',
            TiffConst::TYPE_RATIONAL,
            'RATIONAL',
            1645,
            1646,
            1647,
            'be > 0',
        );

        if ($limit === null) {
            return;
        }

        if (($limit <= 0.0) || ($limit > 1.0)) {
            throw new ParseError(
                sprintf('LinearResponseLimit must be in (0.0, 1.0], got %.6F.', $limit),
                1648,
            );
        }
    }

    /**
     * Validates LinearizationTable (0xC618) DNG layout.
     *
     * DNG 1.7.1.0 defines LinearizationTable as a non-empty SHORT lookup table.
     */
    public function validateDngLinearizationTable(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::LINEARIZATION_TABLE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_SHORT) || ($entry->count < 1)) {
            throw new ParseError(
                sprintf(
                    'LinearizationTable must be SHORT with count >= 1, got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1671,
            );
        }
    }

    /**
     * Validates DefaultUserCrop (0xC7B5) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[4]: (Top, Left, Bottom, Right) with 0 <= Top < Bottom <= 1.0
     * and 0 <= Left < Right <= 1.0.
     */
    public function validateDngDefaultUserCrop(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::DEFAULT_USER_CROP);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_RATIONAL || $entry->count !== 4) {
            throw new ParseError(
                sprintf('DefaultUserCrop must be RATIONAL[4], got type %d count %d.', $entry->type, $entry->count),
                1565,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifRationalList || count($value->values) !== 4) {
            return;
        }

        $top    = $value->values[0]->denominator !== 0 ? $value->values[0]->numerator / $value->values[0]->denominator : -1.0;
        $left   = $value->values[1]->denominator !== 0 ? $value->values[1]->numerator / $value->values[1]->denominator : -1.0;
        $bottom = $value->values[2]->denominator !== 0 ? $value->values[2]->numerator / $value->values[2]->denominator : -1.0;
        $right  = $value->values[3]->denominator !== 0 ? $value->values[3]->numerator / $value->values[3]->denominator : -1.0;

        if ($top < 0.0 || $left < 0.0 || $bottom > 1.0 || $right > 1.0) {
            throw new ParseError(
                sprintf('DefaultUserCrop values must be in [0.0, 1.0], got (%.4f, %.4f, %.4f, %.4f).', $top, $left, $bottom, $right),
                1566,
            );
        }

        if ($top >= $bottom) {
            throw new ParseError(
                sprintf('DefaultUserCrop requires Top < Bottom, got %.4f >= %.4f.', $top, $bottom),
                1567,
            );
        }

        if ($left >= $right) {
            throw new ParseError(
                sprintf('DefaultUserCrop requires Left < Right, got %.4f >= %.4f.', $left, $right),
                1568,
            );
        }
    }

    /**
     * Validates DefaultBlackRender (0xC7A6) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value 0 (Auto) or 1 (None).
     */
    public function validateDngDefaultBlackRender(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::DEFAULT_BLACK_RENDER);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('DefaultBlackRender must be LONG[1], got type %d count %d.', $entry->type, $entry->count),
                1569,
            );
        }

        if (!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)) {
            throw new ParseError(
                sprintf('DefaultBlackRender value must be 0 (Auto) or 1 (None), got %d.', is_int($entry->value) ? $entry->value : -1),
                1570,
            );
        }
    }

    /**
     * Validates BaselineNoise and BaselineSharpness scalar tags per DNG 1.7.1.0.
     *
     * Both tags must be RATIONAL[1] with strictly positive finite values.
     */
    public function validateDngBaselineScalars(Ifd $ifd): void
    {
        $tagNames = [
            DngTag::BASELINE_NOISE     => 'BaselineNoise',
            DngTag::BASELINE_SHARPNESS => 'BaselineSharpness',
        ];

        foreach ($tagNames as $tag => $name) {
            $scalar = $this->support->extractRationalScalar(
                $ifd,
                $tag,
                $name,
                TiffConst::TYPE_RATIONAL,
                'RATIONAL',
                1654,
                1655,
                1656,
                'be > 0',
            );

            if ($scalar === null) {
                continue;
            }

            if (!is_finite($scalar) || ($scalar <= 0.0)) {
                throw new ParseError(
                    sprintf('%s must be a positive finite scalar, got %.6F.', $name, $scalar),
                    1657,
                );
            }
        }
    }

    /**
     * Validates DNG rendering scalar tags.
     *
     * DNG 1.7.1.0 defines these tags as RATIONAL[1] processing controls:
     * - ChromaBlurRadius (>= 0)
     * - AntiAliasStrength (>= 0)
     * - ShadowScale (> 0)
     */
    public function validateDngRenderScalars(Ifd $ifd): void
    {
        /** @var array<int, array{name: string, strictPositive: bool}> $tagRules */
        $tagRules = [
            DngTag::CHROMA_BLUR_RADIUS  => ['name' => 'ChromaBlurRadius', 'strictPositive' => false],
            DngTag::ANTI_ALIAS_STRENGTH => ['name' => 'AntiAliasStrength', 'strictPositive' => false],
            DngTag::SHADOW_SCALE        => ['name' => 'ShadowScale', 'strictPositive' => true],
        ];

        foreach ($tagRules as $tag => $rule) {
            $scalar = $this->support->extractRationalScalar(
                $ifd,
                $tag,
                $rule['name'],
                TiffConst::TYPE_RATIONAL,
                'RATIONAL',
                1662,
                1663,
                1664,
                'be > 0',
            );

            if ($scalar === null) {
                continue;
            }

            if (!is_finite($scalar)) {
                throw new ParseError(
                    sprintf('%s must be finite.', $rule['name']),
                    1665,
                );
            }

            if ($rule['strictPositive'] ? ($scalar <= 0.0) : ($scalar < 0.0)) {
                throw new ParseError(
                    sprintf(
                        '%s must be %s, got %.6F.',
                        $rule['name'],
                        $rule['strictPositive'] ? '> 0' : '>= 0',
                        $scalar,
                    ),
                    1666,
                );
            }
        }
    }

    /**
     * Validates LensInfo (0xC630) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[4] in this order:
     * 1) min focal length, 2) max focal length, 3) min f-stop at min focal,
     * 4) min f-stop at max focal.
     *
     * Aperture fields may use 0/0 to indicate unknown values.
     */
    public function validateDngLensInfo(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::LENS_INFO);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 4)) {
            throw new ParseError(
                sprintf(
                    'LensInfo must be RATIONAL[4], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1649,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRationalList || count($value->values) !== 4) {
            throw new ParseError('LensInfo must decode to four rational components.', 1650);
        }

        $components = [];
        foreach ($value->values as $index => $component) {
            if ($component->denominator === 0) {
                $isApertureField = $index >= 2;
                if ($isApertureField && $component->numerator === 0) {
                    $components[] = null;
                    continue;
                }

                throw new ParseError(
                    sprintf('LensInfo component %d has invalid zero denominator.', $index),
                    1651,
                );
            }

            $components[] = $component->numerator / $component->denominator;
        }

        $minFocal = (float) $components[0];
        $maxFocal = (float) $components[1];

        if ($minFocal > $maxFocal) {
            throw new ParseError(
                sprintf(
                    'LensInfo minimum focal length %.6F must be <= maximum focal length %.6F.',
                    $minFocal,
                    $maxFocal,
                ),
                1653,
            );
        }
    }

    /**
     * Validates BayerGreenSplit (0xC62D) in DNG contexts.
     *
     * DNG 1.7.1.0 defines BayerGreenSplit as LONG[1], non-negative, and
     * applicable to Bayer CFA images.
     *
     * Applicability is enforced when contextual tags are present:
     * - PhotometricInterpretation must be CFA (32803)
     * - CFARepeatPatternDim must be 2x2 for Bayer
     */
    public function validateDngBayerGreenSplit(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::BAYER_GREEN_SPLIT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_LONG) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BayerGreenSplit must be LONG[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1658,
            );
        }

        if (!is_int($entry->value) || ($entry->value < 0)) {
            throw new ParseError(
                sprintf('BayerGreenSplit must be a non-negative scalar, got %d.', is_int($entry->value) ? $entry->value : -1),
                1659,
            );
        }

        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (($photo instanceof IfdEntry) && is_int($photo->value) && ($photo->value !== Photometric::Cfa->value)) {
            throw new ParseError(
                sprintf(
                    'BayerGreenSplit requires PhotometricInterpretation=%d, got %d.',
                    Photometric::Cfa->value,
                    $photo->value,
                ),
                1660,
            );
        }

        $repeat = $ifd->get(DngTag::CFA_REPEAT_PATTERN_DIM);

        if (!$repeat instanceof IfdEntry || !$repeat->value instanceof ExifNumericList || count($repeat->value->values) !== 2) {
            return;
        }

        $rows = $repeat->value->values[0];
        $cols = $repeat->value->values[1];

        if (!is_int($rows) || !is_int($cols)) {
            return;
        }

        if (($rows !== 2) || ($cols !== 2)) {
            throw new ParseError(
                sprintf('BayerGreenSplit requires Bayer CFARepeatPatternDim=2x2, got %dx%d.', $rows, $cols),
                1661,
            );
        }
    }

    /**
     * Validates BlackLevelRepeatDim and BlackLevel type/count constraints.
     *
     * DNG 1.7.1.0:
     * - BlackLevelRepeatDim: SHORT[2], both values positive.
     * - BlackLevel: SHORT|LONG|RATIONAL, count = rows * cols * SamplesPerPixel.
     *
     * @return array{0: int|null, 1: int|null} Repeat rows and columns (null when absent).
     */
    private function validateDngBlackLevelRepeatDimAndLevel(
        Ifd $ifd,
        ?int $samplesPerPixel,
    ): array {
        $repeatRows = null;
        $repeatCols = null;
        $repeatDim  = $ifd->get(DngTag::BLACK_LEVEL_REPEAT_DIM);

        if ($repeatDim instanceof IfdEntry) {
            if (($repeatDim->type !== TiffConst::TYPE_SHORT) || ($repeatDim->count !== 2)) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelRepeatDim must be SHORT[2], got type %d count %d.',
                        $repeatDim->type,
                        $repeatDim->count,
                    ),
                    1614,
                );
            }

            [$repeatRows, $repeatCols] = $this->support->extractDngPositivePairFromNumericList($repeatDim, 'BlackLevelRepeatDim');
        }

        $this->validateDngBlackLevelEntry($ifd, $repeatRows, $repeatCols, $samplesPerPixel);

        return [$repeatRows, $repeatCols];
    }

    /**
     * Validates BlackLevel type and count against RepeatDim and SamplesPerPixel.
     *
     * DNG 1.7.1.0:
     * - BlackLevel: SHORT|LONG|RATIONAL, count = rows * cols * SamplesPerPixel.
     */
    private function validateDngBlackLevelEntry(
        Ifd $ifd,
        ?int $repeatRows,
        ?int $repeatCols,
        ?int $samplesPerPixel,
    ): void {
        $blackLevel = $ifd->get(DngTag::BLACK_LEVEL);
        if (!$blackLevel instanceof IfdEntry) {
            return;
        }

        if (
            !in_array(
                $blackLevel->type,
                [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                true,
            )
        ) {
            throw new ParseError(
                sprintf(
                    'BlackLevel must be SHORT|LONG|RATIONAL, got type %d.',
                    $blackLevel->type,
                ),
                1615,
            );
        }

        if (($repeatRows !== null) && ($repeatCols !== null) && ($samplesPerPixel !== null)) {
            $expectedCount = $repeatRows * $repeatCols * $samplesPerPixel;
            if ($blackLevel->count !== $expectedCount) {
                throw new ParseError(
                    sprintf(
                        'BlackLevel count %d does not match expected %d (rows=%d, cols=%d, SamplesPerPixel=%d).',
                        $blackLevel->count,
                        $expectedCount,
                        $repeatRows,
                        $repeatCols,
                        $samplesPerPixel,
                    ),
                    1616,
                );
            }
        }
    }

    /**
     * Resolves ActiveArea dimensions for BlackLevelDelta count validation.
     *
     * DNG 1.7.1.0:
     * - ActiveArea: SHORT|LONG[4] rectangle (top, left, bottom, right).
     *
     * @return array{0: int|null, 1: int|null} Active width and length (null when absent/unusable).
     */
    private function resolveDngActiveAreaDimensions(Ifd $ifd): array
    {
        $activeWidth  = null;
        $activeLength = null;
        $activeArea   = $ifd->get(DngTag::ACTIVE_AREA);

        if (
            $activeArea instanceof IfdEntry
            && in_array($activeArea->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
            && ($activeArea->count === 4)
        ) {
            $rectangles = $this->support->extractDngRectangles($activeArea, 'ActiveArea');
            if (count($rectangles) === 1) {
                $activeWidth  = $rectangles[0]['right'] - $rectangles[0]['left'];
                $activeLength = $rectangles[0]['bottom'] - $rectangles[0]['top'];
            }
        }

        return [$activeWidth, $activeLength];
    }

    /**
     * Validates BlackLevelDeltaH and BlackLevelDeltaV type and count constraints.
     *
     * DNG 1.7.1.0:
     * - BlackLevelDeltaH: SRATIONAL, count = ActiveArea width.
     * - BlackLevelDeltaV: SRATIONAL, count = ActiveArea length.
     */
    private function validateDngBlackLevelDeltas(
        Ifd $ifd,
        ?int $activeWidth,
        ?int $activeLength,
    ): void {
        $blackLevelDeltaH = $ifd->get(DngTag::BLACK_LEVEL_DELTA_H);
        if ($blackLevelDeltaH instanceof IfdEntry) {
            $this->validateDngBlackLevelDeltaEntry(
                $blackLevelDeltaH,
                'BlackLevelDeltaH',
                $activeWidth,
                'ActiveArea width',
                1617,
                1618,
            );
        }

        $blackLevelDeltaV = $ifd->get(DngTag::BLACK_LEVEL_DELTA_V);
        if ($blackLevelDeltaV instanceof IfdEntry) {
            $this->validateDngBlackLevelDeltaEntry(
                $blackLevelDeltaV,
                'BlackLevelDeltaV',
                $activeLength,
                'ActiveArea length',
                1619,
                1620,
            );
        }
    }

    /**
     * Validates a single BlackLevelDelta entry type and count against an ActiveArea dimension.
     *
     * DNG 1.7.1.0:
     * - BlackLevelDeltaH/V: SRATIONAL, count = ActiveArea width/length.
     */
    private function validateDngBlackLevelDeltaEntry(
        IfdEntry $entry,
        string $tagName,
        ?int $expectedCount,
        string $dimensionName,
        int $typeErrorCode,
        int $countErrorCode,
    ): void {
        if ($entry->type !== TiffConst::TYPE_SRATIONAL) {
            throw new ParseError(
                sprintf(
                    '%s must be SRATIONAL, got type %d.',
                    $tagName,
                    $entry->type,
                ),
                $typeErrorCode,
            );
        }

        if (($expectedCount !== null) && ($entry->count !== $expectedCount)) {
            throw new ParseError(
                sprintf(
                    '%s count %d does not match %s %d.',
                    $tagName,
                    $entry->count,
                    $dimensionName,
                    $expectedCount,
                ),
                $countErrorCode,
            );
        }
    }

    /**
     * Validates WhiteLevel type and count against SamplesPerPixel.
     *
     * DNG 1.7.1.0:
     * - WhiteLevel: SHORT|LONG, count = SamplesPerPixel.
     */
    private function validateDngWhiteLevel(Ifd $ifd, ?int $samplesPerPixel): void
    {
        $whiteLevel = $ifd->get(DngTag::WHITE_LEVEL);
        if (!$whiteLevel instanceof IfdEntry) {
            return;
        }

        if (!in_array($whiteLevel->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)) {
            throw new ParseError(
                sprintf(
                    'WhiteLevel must be SHORT|LONG, got type %d.',
                    $whiteLevel->type,
                ),
                1621,
            );
        }

        if (($samplesPerPixel !== null) && ($whiteLevel->count !== $samplesPerPixel)) {
            throw new ParseError(
                sprintf(
                    'WhiteLevel count %d does not match SamplesPerPixel %d.',
                    $whiteLevel->count,
                    $samplesPerPixel,
                ),
                1622,
            );
        }
    }

    /**
     * Validates SubTileBlockSize (0xC71E) per DNG 1.7.1.0.
     *
     * Must be (SHORT|LONG)[2] with both components >= 1.
     */
    public function validateDngSubTileBlockSize(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::SUB_TILE_BLOCK_SIZE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (
            ($entry->type !== TiffConst::TYPE_SHORT && $entry->type !== TiffConst::TYPE_LONG)
            || $entry->count !== 2
        ) {
            throw new ParseError(
                sprintf('SubTileBlockSize must be (SHORT|LONG)[2], got type %d count %d.', $entry->type, $entry->count),
                1577,
            );
        }

        if (!$entry->value instanceof ExifNumericList) {
            return;
        }

        $rows = $entry->value->values[0];
        $cols = $entry->value->values[1];

        if (!is_int($rows) || !is_int($cols)) {
            return;
        }

        if ($rows < 1 || $cols < 1) {
            throw new ParseError(
                sprintf('SubTileBlockSize components must be >= 1, got %d, %d.', $rows, $cols),
                1578,
            );
        }
    }

    /**
     * Validates RowInterleaveFactor (0xC71F) per DNG 1.7.1.0.
     *
     * Must be (SHORT|LONG)[1] with value >= 1.
     */
    public function validateDngRowInterleaveFactor(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ROW_INTERLEAVE_FACTOR);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (
            ($entry->type !== TiffConst::TYPE_SHORT && $entry->type !== TiffConst::TYPE_LONG)
            || $entry->count !== 1
        ) {
            throw new ParseError(
                sprintf('RowInterleaveFactor must be (SHORT|LONG)[1], got type %d count %d.', $entry->type, $entry->count),
                1579,
            );
        }

        if (!is_int($entry->value) || $entry->value < 1) {
            throw new ParseError(
                sprintf('RowInterleaveFactor must be >= 1, got %d.', is_int($entry->value) ? $entry->value : -1),
                1580,
            );
        }
    }
}
