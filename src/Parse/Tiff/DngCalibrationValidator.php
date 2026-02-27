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
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Value\Enum\LightSource;

use function count;
use function in_array;
use function is_finite;
use function is_int;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Validates DNG calibration, illuminant, and matrix constraints.
 *
 * DNG 1.7.1.0 defines calibration illuminant domains, matrix dimensional rules,
 * white-balance exclusivity, analog balance semantics, and triple-illuminant
 * cross-tag dependencies validated by this class.
 */
final readonly class DngCalibrationValidator
{
    public function __construct(
        private DngValidationSupport $support,
    ) {
    }

    /**
     * DNG matrix tag count formulas keyed by tag constant.
     *
     * Each entry maps to either 'colorTimesThree' (ColorPlanes × 3) or
     * 'colorSquared' (ColorPlanes × ColorPlanes).
     *
     * DNG 1.7.1.0 pp. 32–42 (ColorMatrix/CameraCalibration/ReductionMatrix),
     * pp. 58–61 (ForwardMatrix), pp. 87–90 (tertiary tags).
     *
     * @var array<int, 'colorTimesThree'|'colorSquared'>
     */
    private const array DNG_MATRIX_COUNT_RULES = [
        DngTag::COLOR_MATRIX_1       => 'colorTimesThree',
        DngTag::COLOR_MATRIX_2       => 'colorTimesThree',
        DngTag::COLOR_MATRIX_3       => 'colorTimesThree',
        DngTag::CAMERA_CALIBRATION_1 => 'colorSquared',
        DngTag::CAMERA_CALIBRATION_2 => 'colorSquared',
        DngTag::CAMERA_CALIBRATION_3 => 'colorSquared',
        DngTag::REDUCTION_MATRIX_1   => 'colorTimesThree',
        DngTag::REDUCTION_MATRIX_2   => 'colorTimesThree',
        DngTag::REDUCTION_MATRIX_3   => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_1     => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_2     => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_3     => 'colorTimesThree',
    ];

    /**
     * CalibrationIlluminant tags that must have valid LightSource values.
     *
     * @var list<int>
     */
    private const array DNG_CALIBRATION_ILLUMINANT_TAGS = [
        DngTag::CALIBRATION_ILLUMINANT_1,
        DngTag::CALIBRATION_ILLUMINANT_2,
        DngTag::CALIBRATION_ILLUMINANT_3,
    ];

    /**
     * DNG calibration illuminant → illuminant data dependency pairs.
     *
     * DNG 1.7.1.0 pp. 43–44, 86, 91–93: when a CalibrationIlluminant tag has value
     * 255 (Other), the corresponding IlluminantData tag is required.
     *
     * @var array<int, int>
     */
    private const array DNG_ILLUMINANT_DATA_DEPS = [
        DngTag::CALIBRATION_ILLUMINANT_1 => DngTag::ILLUMINANT_DATA_1,
        DngTag::CALIBRATION_ILLUMINANT_2 => DngTag::ILLUMINANT_DATA_2,
        DngTag::CALIBRATION_ILLUMINANT_3 => DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * All-or-none DNG tag sets for triple-illuminant validation.
     *
     * DNG 1.7.1.0 "Requirements for three calibrations": ForwardMatrix,
     * ReductionMatrix, and ProfileHueSatMapData must be present for all
     * three illuminants or none.
     *
     * @var list<array{int, int, int}>
     */
    private const array DNG_TRIPLE_ALL_OR_NONE_SETS = [
        [DngTag::FORWARD_MATRIX_1, DngTag::FORWARD_MATRIX_2, DngTag::FORWARD_MATRIX_3],
        [DngTag::REDUCTION_MATRIX_1, DngTag::REDUCTION_MATRIX_2, DngTag::REDUCTION_MATRIX_3],
    ];

    /**
     * Validates DNG matrix tags against ColorPlanes-driven count and SRATIONAL type rules.
     *
     * DNG 1.7.1.0 pp. 32–42 defines matrix dimensional rules driven by the number of
     * color planes derived from CfaPlaneColor (Tag 0xC616). Each matrix tag must use
     * SRATIONAL type and match the expected element count.
     */
    public function validateDngMatrixTags(Ifd $ifd): void
    {
        $cfaEntry = $ifd->get(DngTag::CFA_PLANE_COLOR);

        if (!$cfaEntry instanceof IfdEntry) {
            return;
        }

        $colorPlanes = $cfaEntry->count;

        // DNG 1.7.1.0 p. 32: ColorMatrix1 is required for all non-monochrome DNG files.
        if (($colorPlanes > 1) && (!$ifd->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry)) {
            throw new ParseError(
                'ColorMatrix1 is required for non-monochrome DNG files per DNG 1.7.1.0.',
                1999,
            );
        }

        foreach (self::DNG_MATRIX_COUNT_RULES as $tag => $formula) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_SRATIONAL) {
                throw new ParseError(
                    sprintf(
                        'DNG matrix tag 0x%04X requires SRATIONAL type, got %d per DNG 1.7.1.0.',
                        $tag,
                        $entry->type,
                    ),
                    1996,
                );
            }

            $expected = $formula === 'colorSquared'
                ? $colorPlanes * $colorPlanes
                : $colorPlanes * 3;

            if ($entry->count !== $expected) {
                throw new ParseError(
                    sprintf(
                        'DNG matrix tag 0x%04X count %d does not match expected %d (ColorPlanes=%d) per DNG 1.7.1.0.',
                        $tag,
                        $entry->count,
                        $expected,
                        $colorPlanes,
                    ),
                    1470,
                );
            }
        }
    }

    /**
     * Validates CalibrationIlluminant values against the EXIF LightSource domain
     * and enforces DNG version gating for value 255 (Other).
     */
    public function validateDngCalibrationIlluminantDomain(Ifd $ifd): void
    {
        $dngVer = $this->support->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        foreach (self::DNG_CALIBRATION_ILLUMINANT_TAGS as $tag) {
            $entry = $ifd->get($tag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            $value = $entry->value;

            if (LightSource::tryFrom($value) === null) {
                throw new ParseError(
                    sprintf(
                        'CalibrationIlluminant 0x%04X value %d is not a valid EXIF LightSource.',
                        $tag,
                        $value,
                    ),
                    2033,
                );
            }

            if (($value === 255) && ($dngVer !== null) && $this->support->dngVersionLessThan($dngVer, [1, 6, 0, 0])) {
                throw new ParseError(
                    sprintf(
                        'CalibrationIlluminant 0x%04X = 255 (Other) requires DNG >= 1.6.0.0, got %d.%d.%d.%d.',
                        $tag,
                        $dngVer[0],
                        $dngVer[1],
                        $dngVer[2],
                        $dngVer[3],
                    ),
                    2037,
                );
            }
        }
    }

    /**
     * Validates DNG calibration illuminant conditional dependencies.
     *
     * DNG 1.7.1.0 pp. 43–44, 91–93: when CalibrationIlluminant{1,2,3} = 255 (Other),
     * the corresponding IlluminantData{1,2,3} tag must be present.
     */
    public function validateDngIlluminantDependencies(Ifd $ifd): void
    {
        foreach (self::DNG_ILLUMINANT_DATA_DEPS as $illuminantTag => $dataTag) {
            $entry = $ifd->get($illuminantTag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            if ($entry->value !== 255) {
                continue;
            }

            if (!$ifd->get($dataTag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'CalibrationIlluminant 0x%04X = 255 (Other) requires IlluminantData 0x%04X per DNG 1.7.1.0.',
                        $illuminantTag,
                        $dataTag,
                    ),
                    1997,
                );
            }
        }
    }

    /**
     * Validates DNG triple-illuminant cross-tag dependencies.
     *
     * DNG 1.7.1.0 "Requirements for three calibrations": when CalibrationIlluminant3
     * is present, CalibrationIlluminant1/2, ColorMatrix3, and all-or-none tag sets
     * must be structurally complete with distinct illuminant values.
     */
    public function validateDngTripleIlluminant(Ifd $ifd): void
    {
        $illum3 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_3);

        if (!$illum3 instanceof IfdEntry) {
            return;
        }

        // CalibrationIlluminant1 and CalibrationIlluminant2 must also be present
        if (!$ifd->get(DngTag::CALIBRATION_ILLUMINANT_1) instanceof IfdEntry || !$ifd->get(DngTag::CALIBRATION_ILLUMINANT_2) instanceof IfdEntry) {
            throw new ParseError(
                'CalibrationIlluminant3 requires CalibrationIlluminant1 and CalibrationIlluminant2 per DNG 1.7.1.0.',
                2003,
            );
        }

        // ColorMatrix3 must be present
        if (!$ifd->get(DngTag::COLOR_MATRIX_3) instanceof IfdEntry) {
            throw new ParseError(
                'CalibrationIlluminant3 requires ColorMatrix3 per DNG 1.7.1.0.',
                2005,
            );
        }

        // All-or-none tag sets
        foreach (self::DNG_TRIPLE_ALL_OR_NONE_SETS as $set) {
            $present = 0;
            foreach ($set as $tag) {
                if ($ifd->get($tag) instanceof IfdEntry) {
                    ++$present;
                }
            }

            if (($present !== 0) && ($present !== 3)) {
                throw new ParseError(
                    sprintf(
                        'DNG triple-illuminant tag set 0x%04X/0x%04X/0x%04X must be all-or-none per DNG 1.7.1.0.',
                        $set[0],
                        $set[1],
                        $set[2],
                    ),
                    1475,
                );
            }
        }

        // Illuminant values must be distinct (illum1/illum2 guaranteed present above)
        /** @var IfdEntry $illum1 */
        $illum1 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_1);
        /** @var IfdEntry $illum2 */
        $illum2 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_2);

        if (is_int($illum1->value) && is_int($illum2->value) && is_int($illum3->value) && ($illum1->value === $illum2->value || $illum1->value === $illum3->value || $illum2->value === $illum3->value)) {
            throw new ParseError(
                'Triple-illuminant CalibrationIlluminant values must be distinct per DNG 1.7.1.0.',
                2007,
            );
        }
    }

    /**
     * Validates DNG white-balance tag mutual exclusivity.
     *
     * DNG 1.7.1.0 pp. 36–37: AsShotNeutral and AsShotWhiteXY are mutually
     * exclusive; both must not be present in the same IFD.
     */
    public function validateDngWhiteBalanceExclusivity(Ifd $ifd): void
    {
        if (($ifd->get(DngTag::AS_SHOT_NEUTRAL) instanceof IfdEntry) && ($ifd->get(DngTag::AS_SHOT_WHITE_XY) instanceof IfdEntry)) {
            throw new ParseError(
                'AsShotNeutral and AsShotWhiteXY are mutually exclusive per DNG 1.7.1.0.',
                2009,
            );
        }
    }

    /**
     * Validates DNG white-balance tag type and count constraints.
     *
     * AsShotNeutral: SHORT or RATIONAL, count = ColorPlanes.
     * AsShotWhiteXY: RATIONAL, count = 2.
     */
    public function validateDngWhiteBalanceLayout(Ifd $ifd): void
    {
        $cfaEntry    = $ifd->get(DngTag::CFA_PLANE_COLOR);
        $colorPlanes = $cfaEntry instanceof IfdEntry ? $cfaEntry->count : null;

        $neutral = $ifd->get(DngTag::AS_SHOT_NEUTRAL);

        if ($neutral instanceof IfdEntry) {
            $validType = $neutral->type === TiffConst::TYPE_SHORT
                || $neutral->type === TiffConst::TYPE_RATIONAL;

            if (!$validType || ($colorPlanes !== null && $neutral->count !== $colorPlanes)) {
                throw new ParseError(
                    sprintf(
                        'AsShotNeutral must be SHORT or RATIONAL with count = ColorPlanes (%s) per DNG 1.7.1.0, got type %d count %d.',
                        $colorPlanes !== null ? (string) $colorPlanes : 'unknown',
                        $neutral->type,
                        $neutral->count,
                    ),
                    2019,
                );
            }
        }

        $whiteXY = $ifd->get(DngTag::AS_SHOT_WHITE_XY);

        if (($whiteXY instanceof IfdEntry) && ($whiteXY->type !== TiffConst::TYPE_RATIONAL || $whiteXY->count !== 2)) {
            throw new ParseError(
                sprintf(
                    'AsShotWhiteXY must be RATIONAL with count 2 per DNG 1.7.1.0, got type %d count %d.',
                    $whiteXY->type,
                    $whiteXY->count,
                ),
                2020,
            );
        }
    }

    /**
     * Validates AnalogBalance (0xC627) DNG layout and gain-vector semantics.
     *
     * DNG 1.7.1.0 defines AnalogBalance as RATIONAL[ColorPlanes] with
     * positive finite gain components.
     */
    public function validateDngAnalogBalance(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ANALOG_BALANCE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $colorPlanes = $this->support->resolveDngColorPlanes($ifd);

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || (($colorPlanes !== null) && ($entry->count !== $colorPlanes))) {
            throw new ParseError(
                sprintf(
                    'AnalogBalance must be RATIONAL with count = ColorPlanes (%s), got type %d count %d.',
                    $colorPlanes !== null ? (string) $colorPlanes : 'unknown',
                    $entry->type,
                    $entry->count,
                ),
                1667,
            );
        }

        if (!$entry->value instanceof ExifRationalList || count($entry->value->values) !== $entry->count) {
            throw new ParseError('AnalogBalance must decode to a rational gain vector.', 1668);
        }

        foreach ($entry->value->values as $index => $component) {
            if ($component->denominator <= 0) {
                throw new ParseError(
                    sprintf('AnalogBalance component %d denominator must be > 0.', $index),
                    1669,
                );
            }

            $gain = $component->numerator / $component->denominator;

            if (!is_finite($gain) || ($gain <= 0.0)) {
                throw new ParseError(
                    sprintf('AnalogBalance component %d must be a positive finite gain, got %.6F.', $index, $gain),
                    1670,
                );
            }
        }
    }

    /**
     * Validates that when both CalibrationIlluminant1 and CalibrationIlluminant2
     * are present, neither has value 0 (unknown).
     */
    public function validateDngCalibrationIlluminantPairZero(Ifd $ifd): void
    {
        $illum1 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_1);
        $illum2 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_2);

        if (!$illum1 instanceof IfdEntry || !$illum2 instanceof IfdEntry) {
            return;
        }

        if ((is_int($illum1->value) && $illum1->value === 0) || (is_int($illum2->value) && $illum2->value === 0)) {
            throw new ParseError(
                'CalibrationIlluminant1 and CalibrationIlluminant2 must not have value 0 (unknown) when both are present per DNG 1.7.1.0.',
                2013,
            );
        }
    }

    /**
     * Validates DNG ColorimetricReference value domain and version gating.
     *
     * Allowed values are 0, 1, 2. Value 2 requires DNGBackwardVersion >= 1.7.0.0.
     */
    public function validateDngColorimetricReference(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::COLORIMETRIC_REFERENCE);

        if (!$entry instanceof IfdEntry || !is_int($entry->value)) {
            return;
        }

        if (!in_array($entry->value, [0, 1, 2], true)) {
            throw new ParseError(
                sprintf('ColorimetricReference value %d is outside the allowed domain {0,1,2}.', $entry->value),
                2029,
            );
        }

        if ($entry->value !== 2) {
            return;
        }

        $bwVer = $this->support->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->support->dngVersionLessThan($bwVer, [1, 7, 0, 0])) {
            throw new ParseError(
                sprintf(
                    'ColorimetricReference value 2 requires DNGBackwardVersion >= 1.7.0.0, got %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                ),
                2031,
            );
        }
    }

    /**
     * Validates DNG AsShot/Current ICC profile and pre-profile matrix pairs.
     *
     * DNG 1.7.1.0 defines paired usage:
     * - AsShotICCProfile with AsShotPreProfileMatrix
     * - CurrentICCProfile with CurrentPreProfileMatrix
     *
     * ICC payload tags must be UNDEFINED and structurally valid ICC blobs.
     * Matrix tags must be SRATIONAL with count = (3 * ColorPlanes) or (ColorPlanes^2).
     */
    public function validateDngIccProfilePairs(Ifd $ifd): void
    {
        /** @var list<array{iccTag: int, iccName: string, matrixTag: int, matrixName: string}> $pairs */
        $pairs = [
            [
                'iccTag'     => DngTag::AS_SHOT_ICC_PROFILE,
                'iccName'    => 'AsShotICCProfile',
                'matrixTag'  => DngTag::AS_SHOT_PRE_PROFILE_MATRIX,
                'matrixName' => 'AsShotPreProfileMatrix',
            ],
            [
                'iccTag'     => DngTag::CURRENT_ICC_PROFILE,
                'iccName'    => 'CurrentICCProfile',
                'matrixTag'  => DngTag::CURRENT_PRE_PROFILE_MATRIX,
                'matrixName' => 'CurrentPreProfileMatrix',
            ],
        ];

        $colorPlanes = $this->support->resolveDngColorPlanes($ifd);
        $iccParser   = new IccParser();

        foreach ($pairs as $pair) {
            $iccEntry    = $ifd->get($pair['iccTag']);
            $matrixEntry = $ifd->get($pair['matrixTag']);
            $hasIcc      = $iccEntry instanceof IfdEntry;
            $hasMatrix   = $matrixEntry instanceof IfdEntry;

            if (!$hasIcc && !$hasMatrix) {
                continue;
            }

            if ($hasIcc !== $hasMatrix) {
                throw new ParseError(
                    sprintf(
                        '%s and %s must be present as a pair per DNG 1.7.1.0.',
                        $pair['iccName'],
                        $pair['matrixName'],
                    ),
                    1676,
                );
            }

            /** @var IfdEntry $iccEntry */
            /** @var IfdEntry $matrixEntry */
            $this->validateDngIccPayloadEntry($iccEntry, $pair['iccName'], $iccParser);
            $this->validateDngPreProfileMatrixEntry($matrixEntry, $pair['matrixName'], $colorPlanes);
        }
    }

    /**
     * Validates a single DNG ICC profile payload entry (type, length and ICC structure).
     *
     * DNG 1.7.1.0:
     * - ICC payload tags must be UNDEFINED and structurally valid ICC blobs.
     */
    private function validateDngIccPayloadEntry(
        IfdEntry $iccEntry,
        string $iccName,
        IccParser $iccParser,
    ): void {
        if (($iccEntry->type !== TiffConst::TYPE_UNDEFINED) || ($iccEntry->count < 1) || !is_string($iccEntry->value) || (strlen($iccEntry->value) !== $iccEntry->count)) {
            throw new ParseError(
                sprintf(
                    '%s must be UNDEFINED with byte-count matching payload length, got type %d count %d.',
                    $iccName,
                    $iccEntry->type,
                    $iccEntry->count,
                ),
                1677,
            );
        }

        try {
            $iccParser->decode($iccEntry->value);
        } catch (ParseError $exception) {
            throw new ParseError(
                sprintf('%s payload is not a valid ICC profile: %s', $iccName, $exception->getMessage()),
                1678,
                $exception,
            );
        }
    }

    /**
     * Validates a single DNG pre-profile matrix entry (type, count and component values).
     *
     * DNG 1.7.1.0:
     * - Matrix tags must be SRATIONAL with count = (3 * ColorPlanes) or (ColorPlanes^2).
     * - All components must have non-zero denominators and finite values.
     */
    private function validateDngPreProfileMatrixEntry(
        IfdEntry $matrixEntry,
        string $matrixName,
        ?int $colorPlanes,
    ): void {
        if ($colorPlanes === null) {
            throw new ParseError(
                sprintf('%s requires resolvable ColorPlanes context.', $matrixName),
                1679,
            );
        }

        if (($matrixEntry->type !== TiffConst::TYPE_SRATIONAL) || ($matrixEntry->count < 1)) {
            throw new ParseError(
                sprintf(
                    '%s must be SRATIONAL with positive count, got type %d count %d.',
                    $matrixName,
                    $matrixEntry->type,
                    $matrixEntry->count,
                ),
                1680,
            );
        }

        $count3n = $colorPlanes * 3;
        $countNn = $colorPlanes * $colorPlanes;

        if (($matrixEntry->count !== $count3n) && ($matrixEntry->count !== $countNn)) {
            throw new ParseError(
                sprintf(
                    '%s count %d must be 3*ColorPlanes (%d) or ColorPlanes^2 (%d).',
                    $matrixName,
                    $matrixEntry->count,
                    $count3n,
                    $countNn,
                ),
                1681,
            );
        }

        $this->validateDngPreProfileMatrixComponents($matrixEntry, $matrixName);
    }

    /**
     * Validates DNG pre-profile matrix SRATIONAL component list and finiteness.
     *
     * DNG 1.7.1.0:
     * - All components must decode to SRATIONAL list.
     * - Denominators must not be zero and values must be finite.
     */
    private function validateDngPreProfileMatrixComponents(IfdEntry $matrixEntry, string $matrixName): void
    {
        if (!$matrixEntry->value instanceof ExifRationalList || count($matrixEntry->value->values) !== $matrixEntry->count) {
            throw new ParseError(
                sprintf('%s must decode to SRATIONAL list with %d components.', $matrixName, $matrixEntry->count),
                1682,
            );
        }

        foreach ($matrixEntry->value->values as $index => $component) {
            if ($component->denominator === 0) {
                throw new ParseError(
                    sprintf('%s component %d denominator must not be zero.', $matrixName, $index),
                    1683,
                );
            }

            $value = $component->numerator / $component->denominator;

            if (!is_finite($value)) {
                throw new ParseError(
                    sprintf('%s component %d must be finite.', $matrixName, $index),
                    1684,
                );
            }
        }
    }
}
