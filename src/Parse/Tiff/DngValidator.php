<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\Photometric;

use function array_any;
use function count;
use function implode;
use function in_array;
use function intdiv;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function preg_match;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Validates DNG (Digital Negative) structural and semantic constraints.
 *
 * DNG 1.7.1.0 defines type/count rules, version gating, cross-tag dependencies,
 * and binary payload layouts validated by this class.
 */
final readonly class DngValidator
{
    public function __construct(
        private Endian $bo,
        private MemoryBuffer $buffer,
    ) {
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    private function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
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
        if ($colorPlanes > 1 && !$ifd->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
            throw new ParseError(
                'ColorMatrix1 is required for non-monochrome DNG files per DNG 1.7.1.0.',
                1472,
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
                    1469,
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
     * Validates CalibrationIlluminant values against the EXIF LightSource domain
     * and enforces DNG version gating for value 255 (Other).
     */
    public function validateDngCalibrationIlluminantDomain(Ifd $ifd): void
    {
        $dngVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

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
                    1496,
                );
            }

            if ($value === 255 && $dngVer !== null && $this->dngVersionLessThan($dngVer, [1, 6, 0, 0])) {
                throw new ParseError(
                    sprintf(
                        'CalibrationIlluminant 0x%04X = 255 (Other) requires DNG >= 1.6.0.0, got %d.%d.%d.%d.',
                        $tag,
                        $dngVer[0],
                        $dngVer[1],
                        $dngVer[2],
                        $dngVer[3],
                    ),
                    1497,
                );
            }
        }
    }

    /**
     * Extracts a 4-byte DNG version tuple from the given IFD entry.
     *
     * @return list<int>|null
     */
    private function extractDngVersionTuple(Ifd $ifd, int $tag): ?array
    {
        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!$value instanceof ExifNumericList || count($value->values) !== 4) {
            return null;
        }

        $tuple = [];
        foreach ($value->values as $c) {
            if (!is_int($c)) {
                return null;
            }

            $tuple[] = $c;
        }

        return $tuple;
    }

    /**
     * Returns the effective DNG backward version for validation.
     *
     * If the explicit DNGBackwardVersion tag is present, its tuple is returned.
     * Otherwise, per DNG 1.7.1.0 §2 the default is derived from DNGVersion
     * as [major, minor, 0, 0].
     *
     * @return list<int>|null Four-element version tuple, or null when DNGVersion is absent.
     */
    private function getEffectiveDngBackwardVersion(Ifd $ifd): ?array
    {
        $explicit = $this->extractDngVersionTuple($ifd, DngTag::DNG_BACKWARD_VERSION);

        if ($explicit !== null) {
            return $explicit;
        }

        $dngVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return null;
        }

        return [$dngVer[0], $dngVer[1], 0, 0];
    }

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
                    1471,
                );
            }
        }
    }

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
     * Third-illuminant tags requiring DNGBackwardVersion >= 1.6.0.0.
     *
     * @var list<int>
     */
    private const array DNG_THIRD_ILLUMINANT_TAGS = [
        DngTag::CALIBRATION_ILLUMINANT_3,
        DngTag::COLOR_MATRIX_3,
        DngTag::FORWARD_MATRIX_3,
        DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * Rejects third-illuminant tags when DNGBackwardVersion < 1.6.0.0.
     *
     * DNG 1.7.1.0 Appendix A: third calibration set requires version >= 1.6.0.0.
     */
    public function validateDngThirdIlluminantVersionFloor(Ifd $ifd): void
    {
        $hasThird = array_any(self::DNG_THIRD_ILLUMINANT_TAGS, fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry);

        if (!$hasThird) {
            return;
        }

        $bwVer = $this->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->dngVersionLessThan($bwVer, [1, 6, 0, 0])) {
            throw new ParseError(
                sprintf(
                    'Third-illuminant tags require DNGBackwardVersion >= 1.6.0.0, got %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                ),
                1500,
            );
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
        if (!$ifd->get(DngTag::CALIBRATION_ILLUMINANT_1) instanceof IfdEntry
            || !$ifd->get(DngTag::CALIBRATION_ILLUMINANT_2) instanceof IfdEntry
        ) {
            throw new ParseError(
                'CalibrationIlluminant3 requires CalibrationIlluminant1 and CalibrationIlluminant2 per DNG 1.7.1.0.',
                1473,
            );
        }

        // ColorMatrix3 must be present
        if (!$ifd->get(DngTag::COLOR_MATRIX_3) instanceof IfdEntry) {
            throw new ParseError(
                'CalibrationIlluminant3 requires ColorMatrix3 per DNG 1.7.1.0.',
                1474,
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

            if ($present !== 0 && $present !== 3) {
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

        if (
            is_int($illum1->value) && is_int($illum2->value) && is_int($illum3->value)
            && ($illum1->value === $illum2->value || $illum1->value === $illum3->value || $illum2->value === $illum3->value)
        ) {
            throw new ParseError(
                'Triple-illuminant CalibrationIlluminant values must be distinct per DNG 1.7.1.0.',
                1476,
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
        if (
            $ifd->get(DngTag::AS_SHOT_NEUTRAL) instanceof IfdEntry
            && $ifd->get(DngTag::AS_SHOT_WHITE_XY) instanceof IfdEntry
        ) {
            throw new ParseError(
                'AsShotNeutral and AsShotWhiteXY are mutually exclusive per DNG 1.7.1.0.',
                1477,
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
                    1486,
                );
            }
        }

        $whiteXY = $ifd->get(DngTag::AS_SHOT_WHITE_XY);

        if ($whiteXY instanceof IfdEntry && ($whiteXY->type !== TiffConst::TYPE_RATIONAL || $whiteXY->count !== 2)) {
            throw new ParseError(
                sprintf(
                    'AsShotWhiteXY must be RATIONAL with count 2 per DNG 1.7.1.0, got type %d count %d.',
                    $whiteXY->type,
                    $whiteXY->count,
                ),
                1487,
            );
        }
    }

    /**
     * Resolves DNG ColorPlanes from available in-IFD metadata.
     *
     * DNG 1.7.1.0 defines ColorPlanes as the number of color components.
     * This parser resolves it from CfaPlaneColor count first, then
     * SamplesPerPixel when available.
     */
    private function resolveDngColorPlanes(Ifd $ifd): ?int
    {
        $cfaEntry = $ifd->get(DngTag::CFA_PLANE_COLOR);

        if (($cfaEntry instanceof IfdEntry) && ($cfaEntry->count > 0)) {
            return $cfaEntry->count;
        }

        $samplesPerPixel = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if (($samplesPerPixel instanceof IfdEntry) && is_int($samplesPerPixel->value) && ($samplesPerPixel->value > 0)) {
            return $samplesPerPixel->value;
        }

        return null;
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

        $colorPlanes = $this->resolveDngColorPlanes($ifd);

        if (
            ($entry->type !== TiffConst::TYPE_RATIONAL)
            || (($colorPlanes !== null) && ($entry->count !== $colorPlanes))
        ) {
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

        if (
            (is_int($illum1->value) && $illum1->value === 0)
            || (is_int($illum2->value) && $illum2->value === 0)
        ) {
            throw new ParseError(
                'CalibrationIlluminant1 and CalibrationIlluminant2 must not have value 0 (unknown) when both are present per DNG 1.7.1.0.',
                1479,
            );
        }
    }

    /**
     * Validates DNG ProfileToneCurve structure and values.
     */
    public function validateDngProfileToneCurve(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_TONE_CURVE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList) {
            return;
        }

        $vals = $value->values;

        if (count($vals) % 2 !== 0) {
            throw new ParseError(
                'ProfileToneCurve FLOAT count must be even (x,y pairs) per DNG 1.7.1.0.',
                1480,
            );
        }

        // Extract typed float array, bail if any value is not numeric
        /** @var list<float> $floats */
        $floats = [];

        foreach ($vals as $v) {
            if (!is_float($v) && !is_int($v)) {
                return;
            }

            $floats[] = (float) $v;
        }

        // Check all values are finite and in [0.0, 1.0]
        foreach ($floats as $fv) {
            if (!is_finite($fv) || $fv < 0.0 || $fv > 1.0) {
                throw new ParseError(
                    'ProfileToneCurve values must be finite floats in [0.0, 1.0] per DNG 1.7.1.0.',
                    1482,
                );
            }
        }

        // Check x values are strictly increasing
        $prevX = -1.0;

        for ($i = 0, $n = count($floats); $i < $n; $i += 2) {
            if ($floats[$i] <= $prevX) {
                throw new ParseError(
                    'ProfileToneCurve x coordinates must be strictly increasing per DNG 1.7.1.0.',
                    1481,
                );
            }

            $prevX = $floats[$i];
        }

        // SDR endpoint check: if ProfileDynamicRange is absent or SDR, enforce (0,0) and (1,1)
        $isSdr    = true;
        $dynRange = $ifd->get(DngTag::PROFILE_DYNAMIC_RANGE);

        if ($dynRange instanceof IfdEntry && is_string($dynRange->value) && strlen($dynRange->value) >= 4) {
            // Bytes 2-3 are DynamicRange SHORT (LE): 0=SDR, 1=HDR
            $range = ord($dynRange->value[2]) | (ord($dynRange->value[3]) << 8);

            if ($range === 1) {
                $isSdr = false;
            }
        }

        if ($isSdr && count($floats) >= 4) {
            $lastIdx = count($floats) - 1;

            if (
                $floats[0] !== 0.0
                || $floats[1] !== 0.0
                || $floats[$lastIdx - 1] !== 1.0
                || $floats[$lastIdx] !== 1.0
            ) {
                throw new ParseError(
                    'SDR ProfileToneCurve must start at (0.0,0.0) and end at (1.0,1.0) per DNG 1.7.1.0.',
                    1483,
                );
            }
        }
    }

    /**
     * Minimum DNGBackwardVersion required for non-default interleave factor tags.
     *
     * @var array<int, list<int>>
     */
    private const array DNG_INTERLEAVE_MIN_VERSIONS = [
        DngTag::ROW_INTERLEAVE_FACTOR    => [1, 2, 0, 0],
        DngTag::COLUMN_INTERLEAVE_FACTOR => [1, 7, 1, 0],
    ];

    /**
     * Validates that non-default interleave factors have a sufficient DNGBackwardVersion.
     */
    public function validateDngInterleaveVersionFloors(Ifd $ifd): void
    {
        $bwVer = $this->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        foreach (self::DNG_INTERLEAVE_MIN_VERSIONS as $tag => $minVer) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            if ($entry->value <= 1) {
                continue;
            }

            if ($this->dngVersionLessThan($bwVer, $minVer)) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X with non-default value %d requires DNGBackwardVersion >= %d.%d.%d.%d, got %d.%d.%d.%d per DNG 1.7.1.0.',
                        $tag,
                        $entry->value,
                        $minVer[0],
                        $minVer[1],
                        $minVer[2],
                        $minVer[3],
                        $bwVer[0],
                        $bwVer[1],
                        $bwVer[2],
                        $bwVer[3],
                    ),
                    1478,
                );
            }
        }
    }

    /**
     * Returns true if version tuple $a is strictly less than $b.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private function dngVersionLessThan(array $a, array $b): bool
    {
        for ($i = 0; $i < 4; ++$i) {
            if ($a[$i] < $b[$i]) {
                return true;
            }

            if ($a[$i] > $b[$i]) {
                return false;
            }
        }

        return false;
    }

    /**
     * Validates that DNG files include the required Orientation tag.
     */
    public function validateDngRequiredOrientation(Ifd $ifd): void
    {
        if (!$ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        if (!$ifd->get(ExifTag::ORIENTATION) instanceof IfdEntry) {
            throw new ParseError(
                'DNG requires Orientation tag in IFD0 per DNG 1.7.1.0.',
                1484,
            );
        }
    }

    /**
     * DNG NewSubFileType-to-PhotometricInterpretation rules.
     * Depth map IFDs (type 8/9) require 51177; semantic mask IFDs (type 65540) require 52527.
     *
     * @var array<int, int>
     */
    private const array DNG_ROLE_PHOTOMETRIC = [
        8     => 51177,
        9     => 51177,
        65540 => 52527,
    ];

    /**
     * Validates that depth map and semantic mask IFDs use their required PhotometricInterpretation.
     */
    public function validateDngRolePhotometric(Ifd $ifd): void
    {
        $subfileEntry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);

        if (!$subfileEntry instanceof IfdEntry || !is_int($subfileEntry->value)) {
            return;
        }

        $required = self::DNG_ROLE_PHOTOMETRIC[$subfileEntry->value] ?? null;

        if ($required === null) {
            return;
        }

        $photoEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photoValue = $photoEntry instanceof IfdEntry && is_int($photoEntry->value) ? $photoEntry->value : null;

        if ($photoValue !== $required) {
            throw new ParseError(
                sprintf(
                    'DNG IFD with NewSubFileType %d requires PhotometricInterpretation %d per DNG 1.7.1.0, got %s.',
                    $subfileEntry->value,
                    $required,
                    $photoValue !== null ? (string) $photoValue : 'none',
                ),
                1485,
            );
        }
    }

    /**
     * DNG tags restricted to IFD 0 per DNG 1.7.1.0.
     *
     * @var list<int>
     */
    private const array DNG_IFD0_ONLY_TAGS = [
        DngTag::DNG_VERSION,
        DngTag::DNG_BACKWARD_VERSION,
        DngTag::UNIQUE_CAMERA_MODEL,
        DngTag::LOCALIZED_CAMERA_MODEL,
        DngTag::AS_SHOT_NEUTRAL,
        DngTag::AS_SHOT_WHITE_XY,
        DngTag::BASELINE_EXPOSURE,
        DngTag::BASELINE_NOISE,
        DngTag::BASELINE_SHARPNESS,
        DngTag::CAMERA_SERIAL_NUMBER,
        DngTag::DNG_PRIVATE_DATA,
        DngTag::MAKER_NOTE_SAFETY,
        DngTag::RAW_DATA_UNIQUE_ID,
        DngTag::ANALOG_BALANCE,
        DngTag::AS_SHOT_ICC_PROFILE,
        DngTag::AS_SHOT_PRE_PROFILE_MATRIX,
        DngTag::CURRENT_ICC_PROFILE,
        DngTag::CURRENT_PRE_PROFILE_MATRIX,
    ];

    /**
     * Rejects DNG IFD0-only tags found in additional IFDs.
     */
    public function validateDngIfd0OnlyTags(Ifd $ifd): void
    {
        foreach (self::DNG_IFD0_ONLY_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X is restricted to IFD 0 per DNG 1.7.1.0 but found in additional IFD.',
                        $tag,
                    ),
                    1488,
                );
            }
        }
    }

    /**
     * Validates DNG JPEG XL tag constraints per DNG 1.7.1.0 §JXL tags.
     *
     * JXLEffort must be 1–9, JXLDecodeSpeed must be 1–4, and all three
     * JXL tags may only appear with Compression = 52546 (JPEG XL).
     */
    public function validateDngJxlTags(Ifd $ifd): void
    {
        $jxlDistance    = $ifd->get(DngTag::JXL_DISTANCE);
        $jxlEffort      = $ifd->get(DngTag::JXL_EFFORT);
        $jxlDecodeSpeed = $ifd->get(DngTag::JXL_DECODE_SPEED);

        $hasJxlTags = $jxlDistance instanceof IfdEntry
            || $jxlEffort instanceof IfdEntry
            || $jxlDecodeSpeed instanceof IfdEntry;

        if (!$hasJxlTags) {
            return;
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);

        if (
            !$compression instanceof IfdEntry
            || !is_int($compression->value)
            || $compression->value !== Compression::JPEG_XL->value
        ) {
            throw new ParseError(
                'JXL tags (JXLDistance, JXLEffort, JXLDecodeSpeed) require Compression = 52546 (JPEG XL).',
                1490,
            );
        }

        if ($jxlEffort instanceof IfdEntry && is_int($jxlEffort->value) && ($jxlEffort->value < 1 || $jxlEffort->value > 9)) {
            throw new ParseError(
                sprintf('JXLEffort must be 1–9, got %d.', $jxlEffort->value),
                1489,
            );
        }

        if ($jxlDecodeSpeed instanceof IfdEntry && is_int($jxlDecodeSpeed->value) && ($jxlDecodeSpeed->value < 1 || $jxlDecodeSpeed->value > 4)) {
            throw new ParseError(
                sprintf('JXLDecodeSpeed must be 1–4, got %d.', $jxlDecodeSpeed->value),
                1489,
            );
        }

        $spp = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if ($spp instanceof IfdEntry && is_int($spp->value) && $spp->value !== 1 && $spp->value !== 3) {
            throw new ParseError(
                sprintf('JPEG XL SamplesPerPixel must be 1 or 3, got %d.', $spp->value),
                1492,
            );
        }

        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if ($photo instanceof IfdEntry && is_int($photo->value) && !in_array($photo->value, [0, 1, 2, 4, 32803, 34892, 51177, 52527], true)) {
            throw new ParseError(
                sprintf('JPEG XL PhotometricInterpretation %d is not allowed.', $photo->value),
                1493,
            );
        }
    }

    /**
     * Validates CFA photometric cross-tag requirements per DNG 1.7.1.0.
     *
     * When PhotometricInterpretation is CFA (32803), both CFARepeatPatternDim
     * and CFAPattern must be present in the same IFD.
     */
    public function validateDngCfaPhotometric(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== 32803) {
            return;
        }

        if (!$ifd->get(DngTag::CFA_REPEAT_PATTERN_DIM) instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFARepeatPatternDim in the same IFD.',
                1491,
            );
        }

        $cfaEntry = $ifd->get(ExifTag::CFA_PATTERN);

        if (!$cfaEntry instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFAPattern in the same IFD.',
                1491,
            );
        }

        if ($ifd->get(DngTag::CFA_PLANE_COLOR) instanceof IfdEntry) {
            return;
        }

        $cfaValue = $cfaEntry->value;

        if (!$cfaValue instanceof ExifNumericList) {
            return;
        }

        foreach ($cfaValue->values as $color) {
            if (is_int($color) && $color > 2) {
                throw new ParseError(
                    'Non-RGB CFA images require CFAPlaneColor per DNG 1.7.1.0.',
                    1497,
                );
            }
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
                1494,
            );
        }

        if ($entry->value !== 2) {
            return;
        }

        $bwVer = $this->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->dngVersionLessThan($bwVer, [1, 7, 0, 0])) {
            throw new ParseError(
                sprintf(
                    'ColorimetricReference value 2 requires DNGBackwardVersion >= 1.7.0.0, got %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                ),
                1495,
            );
        }
    }

    /**
     * Maximum DNG backward version this parser supports.
     *
     * @var list<int>
     */
    private const array SUPPORTED_DNG_VERSION = [1, 7, 1, 0];

    /**
     * Rejects DNG files whose DNGBackwardVersion exceeds the supported reader version.
     */
    public function validateDngBackwardVersionGate(Ifd $ifd): void
    {
        $bwVer = $this->getEffectiveDngBackwardVersion($ifd);

        if ($bwVer === null) {
            return;
        }

        if ($this->dngVersionLessThan(self::SUPPORTED_DNG_VERSION, $bwVer)) {
            throw new ParseError(
                sprintf(
                    'DNGBackwardVersion %d.%d.%d.%d exceeds supported reader version %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                    ...self::SUPPORTED_DNG_VERSION,
                ),
                1496,
            );
        }
    }

    /**
     * Validates the semantic contents of DNGVersion.
     *
     * Rejects zero tuples (e.g. 0.0.0.0) and versions beyond this library's
     * supported range per DNG 1.7.1.0.
     */
    public function validateDngVersionValidity(Ifd $ifd): void
    {
        $dngVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return;
        }

        if ($dngVer[0] === 0) {
            throw new ParseError(
                sprintf(
                    'DNGVersion %d.%d.%d.%d is invalid (zero major version).',
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                ),
                1498,
            );
        }

        if ($this->dngVersionLessThan(self::SUPPORTED_DNG_VERSION, $dngVer)) {
            throw new ParseError(
                sprintf(
                    'DNGVersion %d.%d.%d.%d exceeds supported version %d.%d.%d.%d.',
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                    ...self::SUPPORTED_DNG_VERSION,
                ),
                1499,
            );
        }
    }

    /**
     * Rejects DNG files where DNGBackwardVersion is higher than DNGVersion.
     */
    public function validateDngBackwardVersionConsistency(Ifd $ifd): void
    {
        $dngVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return;
        }

        $bwVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_BACKWARD_VERSION);

        if ($bwVer === null) {
            return;
        }

        if ($this->dngVersionLessThan($dngVer, $bwVer)) {
            throw new ParseError(
                sprintf(
                    'DNGBackwardVersion %d.%d.%d.%d exceeds DNGVersion %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                    $dngVer[0],
                    $dngVer[1],
                    $dngVer[2],
                    $dngVer[3],
                ),
                1497,
            );
        }
    }

    /**
     * DNG sentinel tags whose presence implies the file is a DNG document.
     *
     * @var list<int>
     */
    private const array DNG_SENTINEL_TAGS = [
        DngTag::UNIQUE_CAMERA_MODEL,
    ];

    /**
     * Requires DNGVersion in IFD0 when DNG-specific tags are present.
     */
    public function validateDngRequiredVersion(Ifd $ifd): void
    {
        if ($ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        foreach (self::DNG_SENTINEL_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X found in IFD 0 but required DNGVersion tag is missing.',
                        $tag,
                    ),
                    1498,
                );
            }
        }
    }

    /**
     * Requires UniqueCameraModel in IFD0 when DNGVersion is present.
     */
    public function validateDngRequiredUniqueCameraModel(Ifd $ifd): void
    {
        if (!$ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        if (!$ifd->get(DngTag::UNIQUE_CAMERA_MODEL) instanceof IfdEntry) {
            throw new ParseError(
                'DNG requires UniqueCameraModel tag in IFD0 per DNG 1.7.1.0.',
                1499,
            );
        }
    }

    /**
     * Validates DNG multi-profile naming rule per DNG 1.7.1.0.
     *
     * When more than one camera profile exists (identified by ColorMatrix1),
     * every profile context must include a ProfileName tag.
     *
     * @param Ifd       $ifd0           Primary IFD.
     * @param list<Ifd> $additionalIfds Additional IFDs (IFD1+).
     */
    public function validateDngMultiProfileName(Ifd $ifd0, array $additionalIfds): void
    {
        $profileIfds = [];

        if ($ifd0->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
            $profileIfds[] = $ifd0;
        }

        foreach ($additionalIfds as $additionalIfd) {
            if ($additionalIfd->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
                $profileIfds[] = $additionalIfd;
            }
        }

        if (count($profileIfds) <= 1) {
            return;
        }

        foreach ($profileIfds as $index => $profileIfd) {
            if (!$profileIfd->get(DngTag::PROFILE_NAME) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf('ProfileName is required for camera profile %d when multiple profiles exist per DNG 1.7.1.0.', $index),
                    1515,
                );
            }
        }
    }

    /**
     * Validates ExtraCameraProfiles offsets and embedded profile payload headers.
     *
     * DNG 1.7.1.0 "ExtraCameraProfiles" defines LONG[count] offsets to camera profile
     * payloads. Each payload starts with a byte-order marker ("II" or "MM"), magic
     * value 0x4352, and a 32-bit inner IFD offset relative to the payload start.
     */
    public function validateDngExtraCameraProfiles(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::EXTRA_CAMERA_PROFILES);
        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG) {
            throw new ParseError(
                'ExtraCameraProfiles must use LONG type per DNG 1.7.1.0.',
                1586,
            );
        }

        if ($entry->count < 1) {
            throw new ParseError(
                'ExtraCameraProfiles must contain at least one profile offset per DNG 1.7.1.0.',
                1587,
            );
        }

        $profileOffsets = $this->extractDngExtraCameraProfileOffsets($entry);
        if (count($profileOffsets) !== $entry->count) {
            throw new ParseError(
                'ExtraCameraProfiles count does not match the number of decoded offsets.',
                1588,
            );
        }

        $blobSize = $this->buffer->size();

        foreach ($profileOffsets as $profileIndex => $profileOffset) {
            if (($profileOffset < 0) || ($profileOffset > ($blobSize - 8))) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles offset #%d (%d) is outside TIFF payload bounds.',
                        $profileIndex + 1,
                        $profileOffset,
                    ),
                    1589,
                );
            }

            $cursorBeforeRead = $this->buffer->tell();
            $this->buffer->seek($profileOffset);
            $profileHeader = $this->buffer->read(8);
            $this->buffer->seek($cursorBeforeRead);

            $byteOrderMarker = substr($profileHeader, 0, 2);
            if ($byteOrderMarker === 'II') {
                $profileIsLittleEndian = true;
            } elseif ($byteOrderMarker === 'MM') {
                $profileIsLittleEndian = false;
            } else {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d has invalid byte-order marker 0x%02X%02X.',
                        $profileIndex + 1,
                        ord($byteOrderMarker[0]),
                        ord($byteOrderMarker[1]),
                    ),
                    1590,
                );
            }

            $magicFormat = $profileIsLittleEndian ? 'v' : 'n';
            $magicValue  = Unpack::int($magicFormat, substr($profileHeader, 2, 2), 'ExtraCameraProfiles magic');
            if ($magicValue !== 0x4352) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d has invalid magic 0x%04X (expected 0x4352).',
                        $profileIndex + 1,
                        $magicValue,
                    ),
                    1591,
                );
            }

            $ifdOffsetFormat = $profileIsLittleEndian ? 'V' : 'N';
            $innerIfdOffset  = Unpack::int(
                $ifdOffsetFormat,
                substr($profileHeader, 4, 4),
                'ExtraCameraProfiles inner IFD offset',
            );

            if ($innerIfdOffset < 8) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d inner IFD offset %d must be >= 8.',
                        $profileIndex + 1,
                        $innerIfdOffset,
                    ),
                    1592,
                );
            }

            $absoluteInnerIfdOffset = $profileOffset + $innerIfdOffset;
            if ($absoluteInnerIfdOffset > ($blobSize - 2)) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d inner IFD offset %d is outside TIFF payload bounds.',
                        $profileIndex + 1,
                        $innerIfdOffset,
                    ),
                    1593,
                );
            }
        }
    }

    /**
     * Normalises ExtraCameraProfiles offset values into an integer list.
     *
     * @return list<int>
     */
    private function extractDngExtraCameraProfileOffsets(IfdEntry $entry): array
    {
        if (is_int($entry->value)) {
            return [$entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $offsets = [];

            foreach ($entry->value->values as $value) {
                if (!is_int($value)) {
                    throw new ParseError(
                        'ExtraCameraProfiles offsets must be LONG integers.',
                        1594,
                    );
                }

                $offsets[] = $value;
            }

            return $offsets;
        }

        throw new ParseError(
            'ExtraCameraProfiles must contain numeric LONG offsets.',
            1595,
        );
    }

    /**
     * Validates DNG NoiseProfile coefficient constraints per DNG 1.7.1.0.
     *
     * Count must be even (pairs of S_i, O_i). Each S_i must be > 0, each O_i must be >= 0.
     */
    public function validateDngNoiseProfile(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::NOISE_PROFILE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList) {
            return;
        }

        $count = count($value->values);

        if ($count < 2 || $count % 2 !== 0) {
            throw new ParseError(
                sprintf('NoiseProfile count must be even (pairs of S,O), got %d.', $count),
                1500,
            );
        }

        for ($i = 0; $i < $count; $i += 2) {
            $s = $value->values[$i];
            $o = $value->values[$i + 1];

            if ((is_float($s) || is_int($s)) && $s <= 0.0) {
                throw new ParseError(
                    sprintf('NoiseProfile S_%d must be > 0, got %g.', $i / 2, $s),
                    1499,
                );
            }

            if ((is_float($o) || is_int($o)) && $o < 0.0) {
                throw new ParseError(
                    sprintf('NoiseProfile O_%d must be >= 0, got %g.', $i / 2, $o),
                    1499,
                );
            }
        }
    }

    /**
     * Validates DNG ProfileHueSatMapDims LONG[3] layout and minimum division constraints.
     *
     * HueDivisions >= 1, SaturationDivisions >= 2, ValueDivisions >= 1.
     */
    public function validateDngHueSatMapDims(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_HUE_SAT_MAP_DIMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 3) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims must be LONG[3], got type %d count %d.', $entry->type, $entry->count),
                1511,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList || count($value->values) !== 3) {
            return;
        }

        $hueDivs = $value->values[0];
        $satDivs = $value->values[1];
        $valDivs = $value->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        if ($hueDivs < 1) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims HueDivisions must be >= 1, got %d.', $hueDivs),
                1512,
            );
        }

        if ($satDivs < 2) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims SaturationDivisions must be >= 2, got %d.', $satDivs),
                1513,
            );
        }

        if ($valDivs < 1) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims ValueDivisions must be >= 1, got %d.', $valDivs),
                1514,
            );
        }
    }

    /**
     * ProfileHueSatMapData tags to validate against ProfileHueSatMapDims.
     *
     * @var list<int>
     */
    private const array HUE_SAT_MAP_DATA_TAGS = [
        DngTag::PROFILE_HUE_SAT_MAP_DATA_1,
        DngTag::PROFILE_HUE_SAT_MAP_DATA_2,
        DngTag::PROFILE_HUE_SAT_MAP_DATA_3_V17,
    ];

    /**
     * Validates DNG ProfileHueSatMapData count/content against ProfileHueSatMapDims.
     *
     * Count must equal HueDivisions * SatDivisions * ValueDivisions * 3.
     * Zero-saturation entries (saturation index 0) must have valueScale == 1.0.
     */
    public function validateDngHueSatMapData(Ifd $ifd): void
    {
        $dimsEntry = $ifd->get(DngTag::PROFILE_HUE_SAT_MAP_DIMS);

        if (!$dimsEntry instanceof IfdEntry) {
            return;
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $hueDivs = $dimsValue->values[0];
        $satDivs = $dimsValue->values[1];
        $valDivs = $dimsValue->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        $expectedCount = $hueDivs * $satDivs * $valDivs * 3;

        foreach (self::HUE_SAT_MAP_DATA_TAGS as $tag) {
            $dataEntry = $ifd->get($tag);

            if (!$dataEntry instanceof IfdEntry) {
                continue;
            }

            $dataValue = $dataEntry->value;

            if (!$dataValue instanceof ExifNumericList) {
                continue;
            }

            $actualCount = count($dataValue->values);

            if ($actualCount !== $expectedCount) {
                throw new ParseError(
                    sprintf(
                        'ProfileHueSatMapData 0x%04X count %d does not match dims %d*%d*%d*3 = %d.',
                        $tag,
                        $actualCount,
                        $hueDivs,
                        $satDivs,
                        $valDivs,
                        $expectedCount,
                    ),
                    1501,
                );
            }

            for ($hue = 0; $hue < $hueDivs; ++$hue) {
                for ($val = 0; $val < $valDivs; ++$val) {
                    $tripleIndex = ($hue * $satDivs * $valDivs + 0 * $valDivs + $val) * 3;
                    $valueScale  = $dataValue->values[$tripleIndex + 2] ?? null;

                    if ((is_float($valueScale) || is_int($valueScale)) && (float) $valueScale !== 1.0) {
                        throw new ParseError(
                            sprintf(
                                'ProfileHueSatMapData 0x%04X zero-saturation entry at index %d has valueScale %g, must be 1.0.',
                                $tag,
                                $tripleIndex / 3,
                                $valueScale,
                            ),
                            1502,
                        );
                    }
                }
            }
        }
    }

    /**
     * IlluminantData tags to validate.
     *
     * @var list<int>
     */
    private const array ILLUMINANT_DATA_TAGS = [
        DngTag::ILLUMINANT_DATA_1,
        DngTag::ILLUMINANT_DATA_2,
        DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * Validates DNG IlluminantData payload structure per DNG 1.7.1.0.
     *
     * DataType 0 = chromaticity (x/y), DataType 1 = spectral (NumLambda >= 2).
     */
    public function validateDngIlluminantData(Ifd $ifd): void
    {
        foreach (self::ILLUMINANT_DATA_TAGS as $tag) {
            $entry = $ifd->get($tag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_string($entry->value)) {
                continue;
            }

            $payload = $entry->value;

            if (strlen($payload) < 2) {
                continue;
            }

            $dataType = $this->unpackU16(substr($payload, 0, 2));

            if ($dataType === 0) {
                continue;
            }

            if ($dataType !== 1) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X has unknown DataType %d; expected 0 or 1.', $tag, $dataType),
                    1504,
                );
            }

            if (strlen($payload) < 6) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X spectral payload too short for NumLambda field.', $tag),
                    1503,
                );
            }

            $numLambda = $this->unpackU32(substr($payload, 2, 4));

            if ($numLambda < 2) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X spectral NumLambda must be >= 2, got %d.', $tag, $numLambda),
                    1503,
                );
            }
        }
    }

    /**
     * Validates DNG ProfileDynamicRange payload structure per DNG 1.7.1.0.
     *
     * Payload must be exactly 8 bytes: Version(SHORT)=1, DynamicRange(SHORT) in {0,1},
     * HintMaxOutputValue(FLOAT) <= 1.0 for SDR (DynamicRange=0).
     */
    public function validateDngProfileDynamicRange(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_DYNAMIC_RANGE);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;

        if (strlen($payload) !== 8) {
            throw new ParseError(
                sprintf('ProfileDynamicRange payload must be 8 bytes, got %d.', strlen($payload)),
                1505,
            );
        }

        $version = $this->unpackU16(substr($payload, 0, 2));

        if ($version !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange Version must be 1, got %d.', $version),
                1506,
            );
        }

        $dynamicRange = $this->unpackU16(substr($payload, 2, 2));

        if ($dynamicRange !== 0 && $dynamicRange !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange DynamicRange must be 0 or 1, got %d.', $dynamicRange),
                1507,
            );
        }

        if ($dynamicRange === 0) {
            $hint = $this->unpackFloat(substr($payload, 4, 4));

            if ($hint > 1.0) {
                throw new ParseError(
                    sprintf('SDR ProfileDynamicRange HintMaxOutputValue must be <= 1.0, got %g.', $hint),
                    1508,
                );
            }
        }
    }

    /**
     * Bytes-per-element map keyed by ProfileGainTableMap2 DataType.
     *
     * @var array<int, int>
     */
    private const array GAIN_TABLE_MAP2_ELEMENT_BYTES = [
        0 => 1,
        1 => 2,
        2 => 2,
        3 => 4,
    ];

    /**
     * Validates DNG ProfileGainTableMap2 binary layout per DNG 1.7.1.0.
     *
     * 80-byte header followed by gain data whose size must match the count formula.
     */
    public function validateDngProfileGainTableMap2(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP_2);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 80) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 payload must be at least 80 bytes, got %d.', $length),
                1516,
            );
        }

        $mapPointsV = $this->unpackU32(substr($payload, 0, 4));
        $mapPointsH = $this->unpackU32(substr($payload, 4, 4));
        $mapPointsN = $this->unpackU32(substr($payload, 40, 4));
        $dataType   = $this->unpackU32(substr($payload, 64, 4));
        $gamma      = $this->unpackFloat(substr($payload, 68, 4));

        if (!isset(self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$dataType])) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 DataType must be 0..3, got %d.', $dataType),
                1517,
            );
        }

        if ($gamma < 0.25 || $gamma > 4.0) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 Gamma must be 0.25..4.0, got %g.', $gamma),
                1518,
            );
        }

        $bytesPerElement = self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$dataType];
        $expectedLength  = 80 + ($bytesPerElement * $mapPointsV * $mapPointsH * $mapPointsN);

        if ($length !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap2 count mismatch: expected %d (80 + %d*%d*%d*%d), got %d.',
                    $expectedLength,
                    $bytesPerElement,
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                    $length,
                ),
                1519,
            );
        }
    }

    /**
     * Validates legacy DNG ProfileGainTableMap (0xCD2D) payload structure.
     *
     * DNG 1.7.1.0 legacy map layout:
     * - 64-byte header
     * - gain array of FLOAT32 entries
     * - total size = 64 + 4 * MapPointsV * MapPointsH * MapPointsN
     */
    public function validateDngProfileGainTableMapLegacy(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_UNDEFINED) || !is_string($entry->value)) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap must be UNDEFINED payload bytes, got type %d.',
                    $entry->type,
                ),
                1685,
            );
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 64) {
            throw new ParseError(
                sprintf('ProfileGainTableMap payload must be at least 64 bytes, got %d.', $length),
                1686,
            );
        }

        $mapPointsV = $this->unpackU32(substr($payload, 0, 4));
        $mapPointsH = $this->unpackU32(substr($payload, 4, 4));
        $mapPointsN = $this->unpackU32(substr($payload, 40, 4));

        // Decode and validate fixed header scalar fields to enforce binary layout.
        $headerScalars = [
            $this->unpackDouble(substr($payload, 8, 8)),
            $this->unpackDouble(substr($payload, 16, 8)),
            $this->unpackDouble(substr($payload, 24, 8)),
            $this->unpackDouble(substr($payload, 32, 8)),
            $this->unpackFloat(substr($payload, 44, 4)),
            $this->unpackFloat(substr($payload, 48, 4)),
            $this->unpackFloat(substr($payload, 52, 4)),
            $this->unpackFloat(substr($payload, 56, 4)),
            $this->unpackFloat(substr($payload, 60, 4)),
        ];

        foreach ($headerScalars as $scalar) {
            if (!is_finite($scalar)) {
                throw new ParseError('ProfileGainTableMap header contains non-finite scalar fields.', 1687);
            }
        }

        if (($mapPointsV < 1) || ($mapPointsH < 1) || ($mapPointsN < 1)) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap MapPoints must be >= 1, got V=%d H=%d N=%d.',
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                ),
                1688,
            );
        }

        if ($mapPointsV > intdiv(PHP_INT_MAX, $mapPointsH)) {
            throw new ParseError('ProfileGainTableMap size multiplication overflow (V*H).', 1689);
        }

        $vh = $mapPointsV * $mapPointsH;

        if ($vh > intdiv(PHP_INT_MAX, $mapPointsN)) {
            throw new ParseError('ProfileGainTableMap size multiplication overflow (V*H*N).', 1690);
        }

        $entryCount = $vh * $mapPointsN;

        if ($entryCount > intdiv(PHP_INT_MAX - 64, 4)) {
            throw new ParseError('ProfileGainTableMap payload size overflow.', 1691);
        }

        $expectedLength = 64 + (4 * $entryCount);

        if ($length !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap payload length mismatch: expected %d (64 + 4*%d*%d*%d), got %d.',
                    $expectedLength,
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                    $length,
                ),
                1692,
            );
        }

        $offset = 64;

        for ($i = 0; $i < $entryCount; ++$i) {
            $gain = $this->unpackFloat(substr($payload, $offset, 4));
            $offset += 4;

            if (!is_finite($gain) || ($gain < 0.0)) {
                throw new ParseError(
                    sprintf('ProfileGainTableMap gain[%d] must be finite and >= 0, got %g.', $i, $gain),
                    1693,
                );
            }
        }
    }

    /**
     * Validates DNG gain-map placement rules per DNG 1.7.1.0.
     *
     * ProfileGainTableMap (0xCD2D) is restricted to Raw IFDs and must not appear
     * in IFD 0. When both ProfileGainTableMap and ProfileGainTableMap2 exist,
     * ProfileGainTableMap2 supersedes.
     */
    public function validateDngGainMapPlacement(Ifd $ifd): void
    {
        if ($ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP) instanceof IfdEntry) {
            throw new ParseError(
                'ProfileGainTableMap (0xCD2D) must not appear in IFD 0; it is restricted to Raw IFDs per DNG 1.7.1.0.',
                1520,
            );
        }
    }

    /**
     * Validates DNG ImageSequenceInfo payload structure per DNG 1.7.1.0.
     *
     * Payload: SequenceID (NUL-terminated, min 8 chars), SequenceType (NUL-terminated, min 1 char),
     * FrameInfo (NUL-terminated), Index (uint32 big-endian), Count (uint32 big-endian), Final (uint8).
     */
    public function validateDngImageSequenceInfo(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::IMAGE_SEQUENCE_INFO);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);
        $offset  = 0;

        // SequenceID: NUL-terminated, minimum 8 chars before NUL
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceID must be NUL-terminated.', 1521);
        }

        $seqIdLen = $nulPos - $offset;

        if ($seqIdLen < 8) {
            throw new ParseError(
                sprintf('ImageSequenceInfo SequenceID must be at least 8 characters, got %d.', $seqIdLen),
                1522,
            );
        }

        $offset = $nulPos + 1;

        // SequenceType: NUL-terminated, minimum 1 char
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceType must be NUL-terminated.', 1523);
        }

        $seqTypeLen = $nulPos - $offset;

        if ($seqTypeLen < 1) {
            throw new ParseError('ImageSequenceInfo SequenceType must be at least 1 character.', 1524);
        }

        $offset = $nulPos + 1;

        // FrameInfo: NUL-terminated (may be empty)
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo FrameInfo must be NUL-terminated.', 1525);
        }

        $offset = $nulPos + 1;

        // Index(4) + Count(4) + Final(1) = 9 bytes remaining
        if (($length - $offset) < 9) {
            throw new ParseError(
                sprintf('ImageSequenceInfo payload truncated: need 9 bytes for Index/Count/Final, got %d.', $length - $offset),
                1526,
            );
        }
    }

    /**
     * Opcode-list tags defined by DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DNG_OPCODE_LIST_TAGS = [
        DngTag::OPCODE_LIST_1 => 'OpcodeList1',
        DngTag::OPCODE_LIST_2 => 'OpcodeList2',
        DngTag::OPCODE_LIST_3 => 'OpcodeList3',
    ];

    /**
     * Validates DNG OpcodeList1/2/3 structural framing.
     *
     * DNG 1.7.1.0 Chapter 7 ("Opcode List Processing") defines big-endian list framing:
     * list count (uint32), then per opcode: OpcodeID (uint32), DNGVersion (uint32),
     * Flags (uint32), ParamByteCount (uint32), and ParamByteCount payload bytes.
     * The same framing was introduced with these tags in DNG 1.3.0.0 and remains
     * unchanged in later versions including DNG 1.7.1.0.
     */
    public function validateDngOpcodeLists(Ifd $ifd): void
    {
        foreach (self::DNG_OPCODE_LIST_TAGS as $tag => $tagName) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
                throw new ParseError(
                    sprintf('%s must use UNDEFINED type, got %d.', $tagName, $entry->type),
                    1633,
                );
            }

            if (!is_string($entry->value)) {
                throw new ParseError(
                    sprintf('%s must decode to raw bytes.', $tagName),
                    1634,
                );
            }

            $this->validateDngOpcodeListPayload($tagName, $entry->value);
        }
    }

    /**
     * Validates one DNG opcode-list payload for structural integrity.
     *
     * @param string $tagName Human-readable opcode-list tag name.
     * @param string $payload Raw opcode-list bytes.
     */
    private function validateDngOpcodeListPayload(string $tagName, string $payload): void
    {
        $length = strlen($payload);

        if ($length < 4) {
            throw new ParseError(
                sprintf('%s payload is truncated before opcode count.', $tagName),
                1635,
            );
        }

        $opcodeCount = Unpack::int('N', substr($payload, 0, 4), sprintf('%s opcode count', $tagName));
        $offset      = 4;

        $maxOpcodeCount = intdiv($length - 4, 16);
        if ($opcodeCount > $maxOpcodeCount) {
            throw new ParseError(
                sprintf(
                    '%s opcode count %d exceeds structural maximum %d for payload length %d.',
                    $tagName,
                    $opcodeCount,
                    $maxOpcodeCount,
                    $length,
                ),
                1636,
            );
        }

        for ($index = 0; $index < $opcodeCount; ++$index) {
            if (($length - $offset) < 16) {
                throw new ParseError(
                    sprintf('%s opcode %d is truncated before fixed header.', $tagName, $index),
                    1637,
                );
            }

            $paramByteCount = Unpack::int(
                'N',
                substr($payload, $offset + 12, 4),
                sprintf('%s opcode %d parameter byte count', $tagName, $index),
            );
            $offset += 16;

            if (($length - $offset) < $paramByteCount) {
                throw new ParseError(
                    sprintf(
                        '%s opcode %d declares %d parameter bytes but only %d remain.',
                        $tagName,
                        $index,
                        $paramByteCount,
                        $length - $offset,
                    ),
                    1638,
                );
            }

            $offset += $paramByteCount;
        }
    }

    /**
     * Validates OriginalRawFileData payload framing.
     *
     * DNG 1.7.1.0 ("OriginalRawFileData") defines UNDEFINED payload bytes in big-endian
     * block order with four compressed forks and four 4-byte type/creator fields.
     * Trailing bytes are allowed for forward compatibility.
     */
    public function validateDngOriginalRawFileData(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ORIGINAL_RAW_FILE_DATA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
            throw new ParseError(
                sprintf('OriginalRawFileData must use UNDEFINED type, got %d.', $entry->type),
                1626,
            );
        }

        if (!is_string($entry->value)) {
            throw new ParseError('OriginalRawFileData must decode to raw bytes.', 1627);
        }

        $payload = $entry->value;
        $offset  = 0;

        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw data fork');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw resource fork');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file type');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file creator');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM data fork');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM resource fork');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file type');
        $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file creator');
    }

    /**
     * Consumes a fixed 4-byte field from OriginalRawFileData.
     */
    private function consumeDngOriginalRawFixedBlock(string $payload, int $offset, string $blockName): int
    {
        if ((strlen($payload) - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s block.', $blockName),
                1628,
            );
        }

        return $offset + 4;
    }

    /**
     * Validates one compressed-fork block in OriginalRawFileData and returns the next offset.
     *
     * @param string $payload   Raw OriginalRawFileData bytes.
     * @param int    $offset    Current parse cursor.
     * @param string $blockName Human-readable block name for error context.
     */
    private function validateDngOriginalRawForkBlock(string $payload, int $offset, string $blockName): int
    {
        $payloadLength = strlen($payload);

        if (($payloadLength - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s length field.', $blockName),
                1629,
            );
        }

        $forkStart  = $offset;
        $forkLength = Unpack::int('N', substr($payload, $offset, 4), sprintf('%s length', $blockName));
        $offset += 4;

        if ($forkLength === 0) {
            return $offset;
        }

        $forkBlocks = intdiv($forkLength + 65535, 65536);
        $indexCount = $forkBlocks + 1;
        $indexBytes = $indexCount * 4;

        if (($payloadLength - $offset) < $indexBytes) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s index table.', $blockName),
                1630,
            );
        }

        $minimumDataOffset = 4 + $indexBytes;
        $previousOffset    = -1;
        $forkDataEnd       = 0;

        for ($index = 0; $index < $indexCount; ++$index) {
            $relativeOffset = Unpack::int(
                'N',
                substr($payload, $offset + ($index * 4), 4),
                sprintf('%s index offset', $blockName),
            );

            if (($relativeOffset < $minimumDataOffset) || ($relativeOffset < $previousOffset)) {
                throw new ParseError(
                    sprintf('OriginalRawFileData has invalid %s index offsets.', $blockName),
                    1631,
                );
            }

            $previousOffset = $relativeOffset;
            $forkDataEnd    = $relativeOffset;
        }

        $forkEnd = $forkStart + $forkDataEnd;
        if ($forkEnd > $payloadLength) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s compressed data.', $blockName),
                1632,
            );
        }

        return $forkEnd;
    }

    /**
     * Bytes per RGB entry keyed by RGBTables PixelType.
     *
     * @var array<int, int>
     */
    private const array RGB_TABLES_PIXEL_BYTES = [
        0 => 3,
        1 => 6,
        2 => 12,
    ];

    /**
     * Validates DNG RGBTables payload structure per DNG 1.7.1.0.
     *
     * Top-level: NumTables (1..20), CompositeMethod ({0,1}).
     * Per-table: Divisions (2..32), PixelType ({0,1,2}), GammaEncoding (0..4),
     * ColorPrimaries (0..4), GamutExtension ({0,1}), then Divisions^3 entries.
     */
    public function validateDngRgbTables(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::RGB_TABLES);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 8) {
            throw new ParseError(
                sprintf('RGBTables payload must be at least 8 bytes, got %d.', $length),
                1527,
            );
        }

        $numTables       = $this->unpackU32(substr($payload, 0, 4));
        $compositeMethod = $this->unpackU32(substr($payload, 4, 4));

        if ($numTables < 1 || $numTables > 20) {
            throw new ParseError(
                sprintf('RGBTables NumTables must be 1..20, got %d.', $numTables),
                1528,
            );
        }

        if ($compositeMethod !== 0 && $compositeMethod !== 1) {
            throw new ParseError(
                sprintf('RGBTables CompositeMethod must be 0 or 1, got %d.', $compositeMethod),
                1529,
            );
        }

        $offset        = 8;
        $zeroNameCount = 0;

        for ($t = 0; $t < $numTables; ++$t) {
            if (($length - $offset) < 2) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d header.', $t),
                    1530,
                );
            }

            $nameLen = $this->unpackU16(substr($payload, $offset, 2));
            $offset += 2;

            if ($nameLen === 0) {
                ++$zeroNameCount;
            }

            $offset += $nameLen;

            if (($length - $offset) < 5) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d fields.', $t),
                    1530,
                );
            }

            $divisions      = ord($payload[$offset]);
            $pixelType      = ord($payload[$offset + 1]);
            $gammaEncoding  = ord($payload[$offset + 2]);
            $colorPrimaries = ord($payload[$offset + 3]);
            $gamutExtension = ord($payload[$offset + 4]);
            $offset += 5;

            if ($divisions < 2 || $divisions > 32) {
                throw new ParseError(
                    sprintf('RGBTables table %d Divisions must be 2..32, got %d.', $t, $divisions),
                    1531,
                );
            }

            if (!isset(self::RGB_TABLES_PIXEL_BYTES[$pixelType])) {
                throw new ParseError(
                    sprintf('RGBTables table %d PixelType must be 0..2, got %d.', $t, $pixelType),
                    1532,
                );
            }

            if ($gammaEncoding > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d GammaEncoding must be 0..4, got %d.', $t, $gammaEncoding),
                    1533,
                );
            }

            if ($colorPrimaries > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d ColorPrimaries must be 0..4, got %d.', $t, $colorPrimaries),
                    1534,
                );
            }

            if ($gamutExtension > 1) {
                throw new ParseError(
                    sprintf('RGBTables table %d GamutExtension must be 0 or 1, got %d.', $t, $gamutExtension),
                    1535,
                );
            }

            $tableDataSize = $divisions * $divisions * $divisions * self::RGB_TABLES_PIXEL_BYTES[$pixelType];
            $offset += $tableDataSize;
        }

        if ($numTables > 1 && $zeroNameCount > 1) {
            throw new ParseError(
                sprintf('RGBTables allows at most one unnamed table when NumTables > 1, got %d.', $zeroNameCount),
                1536,
            );
        }

        if ($offset !== $length) {
            throw new ParseError(
                sprintf('RGBTables payload length mismatch: expected %d bytes, got %d.', $offset, $length),
                1537,
            );
        }
    }

    /**
     * Validates SemanticName/SemanticInstanceID conformance in Semantic Mask IFDs.
     *
     * A Semantic Mask IFD is identified by PhotometricInterpretation = 52527.
     * SemanticName is required in that context per DNG 1.6+.
     */
    public function validateDngSemanticMaskIdentity(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PHOTOMETRIC_MASK->value) {
            return;
        }

        $nameEntry = $ifd->get(DngTag::SEMANTIC_NAME);

        if (!$nameEntry instanceof IfdEntry) {
            throw new ParseError(
                'SemanticName is required in Semantic Mask IFD per DNG 1.6+.',
                1538,
            );
        }

        if ($nameEntry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('SemanticName must use ASCII type, got %d.', $nameEntry->type),
                1539,
            );
        }

        if (!is_string($nameEntry->value) || $nameEntry->value === '') {
            throw new ParseError(
                'SemanticName must not be empty in Semantic Mask IFD.',
                1540,
            );
        }
    }

    /**
     * Validates MaskSubArea (0xCD38) in Semantic Mask IFDs.
     *
     * MaskSubArea must use type LONG with count 4: (T_crop, L_crop, W_full, H_full).
     * Geometric constraints require T_crop + ImageLength <= H_full and
     * L_crop + ImageWidth <= W_full. If geometric constraints fail the tag
     * is ignored per DNG 1.6+ spec (no ParseError for geometry).
     */
    public function validateDngMaskSubArea(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PHOTOMETRIC_MASK->value) {
            return;
        }

        $entry = $ifd->get(DngTag::MASK_SUB_AREA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG) {
            throw new ParseError(
                sprintf('MaskSubArea must use LONG type, got %d.', $entry->type),
                1541,
            );
        }

        if ($entry->count !== 4) {
            throw new ParseError(
                sprintf('MaskSubArea must have count 4, got %d.', $entry->count),
                1542,
            );
        }
    }

    /**
     * Validates DNG ImageStats (0xCD46) payload structure per DNG 1.7.1.0.
     *
     * All ImageStats data is stored in big-endian byte order regardless of TIFF
     * file byte order. Payload: LONG child-count N, then N child entries each
     * containing LONG childTagCode, LONG byteLength L, and L bytes of data.
     * Duplicate child tag codes are rejected.
     */
    public function validateDngImageStats(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::IMAGE_STATS);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 4) {
            throw new ParseError(
                sprintf('ImageStats payload too short for child count (%d bytes).', $length),
                1543,
            );
        }

        // ImageStats is always big-endian
        $childCount = Unpack::int('N', substr($payload, 0, 4), 'ImageStats child count');
        $offset     = 4;
        $seenTags   = [];

        for ($i = 0; $i < $childCount; ++$i) {
            if ($offset + 8 > $length) {
                throw new ParseError(
                    sprintf('ImageStats child entry %d truncated at header (offset %d, length %d).', $i, $offset, $length),
                    1544,
                );
            }

            $childTag    = Unpack::int('N', substr($payload, $offset, 4), 'ImageStats child tag');
            $childLength = Unpack::int('N', substr($payload, $offset + 4, 4), 'ImageStats child length');
            $offset += 8;

            if ($offset + $childLength > $length) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d payload truncated (need %d bytes at offset %d, have %d).', $childTag, $childLength, $offset, $length),
                    1545,
                );
            }

            if (isset($seenTags[$childTag])) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d appears more than once.', $childTag),
                    1546,
                );
            }

            $seenTags[$childTag] = true;
            $offset += $childLength;
        }
    }

    /**
     * Validates ProfileLookTableDims (0xC725) per DNG 1.7.1.0.
     *
     * Must be LONG[3]: HueDivisions >= 1, SaturationDivisions >= 2, ValueDivisions >= 1.
     */
    public function validateDngProfileLookTableDims(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DIMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 3) {
            throw new ParseError(
                sprintf('ProfileLookTableDims must be LONG[3], got type %d count %d.', $entry->type, $entry->count),
                1547,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList || count($value->values) !== 3) {
            return;
        }

        $hueDivs = $value->values[0];
        $satDivs = $value->values[1];
        $valDivs = $value->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        if ($hueDivs < 1) {
            throw new ParseError(
                sprintf('ProfileLookTableDims HueDivisions must be >= 1, got %d.', $hueDivs),
                1548,
            );
        }

        if ($satDivs < 2) {
            throw new ParseError(
                sprintf('ProfileLookTableDims SaturationDivisions must be >= 2, got %d.', $satDivs),
                1549,
            );
        }

        if ($valDivs < 1) {
            throw new ParseError(
                sprintf('ProfileLookTableDims ValueDivisions must be >= 1, got %d.', $valDivs),
                1550,
            );
        }
    }

    /**
     * Validates ProfileLookTableData (0xC726) count against ProfileLookTableDims per DNG 1.7.1.0.
     *
     * Type must be FLOAT. Count must equal HueDivisions * SaturationDivisions * ValueDivisions * 3.
     * If dims is present, data must also be present and vice versa.
     */
    public function validateDngProfileLookTableData(Ifd $ifd): void
    {
        $dimsEntry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DIMS);
        $dataEntry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DATA);

        // Pair consistency: both must be present or both absent
        if ($dimsEntry instanceof IfdEntry && !$dataEntry instanceof IfdEntry) {
            throw new ParseError(
                'ProfileLookTableDims is present but ProfileLookTableData is missing.',
                1551,
            );
        }

        if (!$dimsEntry instanceof IfdEntry && $dataEntry instanceof IfdEntry) {
            throw new ParseError(
                'ProfileLookTableData is present but ProfileLookTableDims is missing.',
                1552,
            );
        }

        if (!$dimsEntry instanceof IfdEntry || !$dataEntry instanceof IfdEntry) {
            return;
        }

        if ($dataEntry->type !== TiffConst::TYPE_FLOAT) {
            throw new ParseError(
                sprintf('ProfileLookTableData must use FLOAT type, got %d.', $dataEntry->type),
                1553,
            );
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $hueDivs = $dimsValue->values[0];
        $satDivs = $dimsValue->values[1];
        $valDivs = $dimsValue->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        $expectedCount = $hueDivs * $satDivs * $valDivs * 3;

        if ($dataEntry->count !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'ProfileLookTableData count %d does not match dims %d*%d*%d*3 = %d.',
                    $dataEntry->count,
                    $hueDivs,
                    $satDivs,
                    $valDivs,
                    $expectedCount,
                ),
                1554,
            );
        }
    }

    /**
     * Validates a DNG encoding tag (ProfileHueSatMapEncoding or ProfileLookTableEncoding).
     *
     * Must be LONG[1] with value 0 (Linear) or 1 (sRGB). Not applicable when the
     * associated dimensions tag has ValueDivisions == 1 (2.5D map/table).
     *
     * @param Ifd    $ifd     IFD to validate
     * @param int    $encTag  Encoding tag constant
     * @param int    $dimsTag Associated dimensions tag constant
     * @param string $name    Human-readable tag name for error messages
     */
    public function validateDngEncodingTag(Ifd $ifd, int $encTag, int $dimsTag, string $name): void
    {
        $entry = $ifd->get($encTag);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('%s must be LONG[1], got type %d count %d.', $name, $entry->type, $entry->count),
                1555,
            );
        }

        if (!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)) {
            throw new ParseError(
                sprintf('%s value must be 0 (Linear) or 1 (sRGB), got %d.', $name, is_int($entry->value) ? $entry->value : -1),
                1556,
            );
        }

        // Not applicable to 2.5D maps (ValueDivisions == 1)
        $dimsEntry = $ifd->get($dimsTag);

        if (!$dimsEntry instanceof IfdEntry) {
            return;
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $valDivs = $dimsValue->values[2];

        if (is_int($valDivs) && $valDivs === 1) {
            throw new ParseError(
                sprintf('%s must not be present for 2.5D tables (ValueDivisions == 1).', $name),
                1557,
            );
        }
    }

    /**
     * DNG digest tags that must be BYTE[16] per DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DIGEST_TAGS = [
        DngTag::PREVIEW_SETTINGS_DIGEST  => 'PreviewSettingsDigest',
        DngTag::RAW_IMAGE_DIGEST         => 'RawImageDigest',
        DngTag::ORIGINAL_RAW_FILE_DIGEST => 'OriginalRawFileDigest',
        DngTag::NEW_RAW_IMAGE_DIGEST     => 'NewRawImageDigest',
    ];

    /**
     * Validates DNG digest tags (RawImageDigest, OriginalRawFileDigest, NewRawImageDigest).
     *
     * Each must be BYTE[16] per DNG 1.7.1.0.
     */
    public function validateDngDigestTags(Ifd $ifd): void
    {
        foreach (self::DIGEST_TAGS as $tag => $name) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_BYTE || $entry->count !== 16) {
                throw new ParseError(
                    sprintf('%s must be BYTE[16], got type %d count %d.', $name, $entry->type, $entry->count),
                    1558,
                );
            }
        }
    }

    /**
     * Validates PreviewColorSpace (0xC71A) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value in 0..4 (Unknown, Gray Gamma 2.2, sRGB, Adobe RGB, ProPhoto RGB).
     */
    public function validateDngPreviewColorSpace(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PREVIEW_COLOR_SPACE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('PreviewColorSpace must be LONG[1], got type %d count %d.', $entry->type, $entry->count),
                1559,
            );
        }

        if (!is_int($entry->value) || $entry->value < 0 || $entry->value > 4) {
            throw new ParseError(
                sprintf('PreviewColorSpace value must be 0..4, got %d.', is_int($entry->value) ? $entry->value : -1),
                1560,
            );
        }
    }

    /**
     * Validates PreviewDateTime (0xC71B) per DNG 1.7.1.0.
     *
     * Must be ASCII with a valid ISO 8601 date/time string.
     * NUL termination is already enforced by the generic ASCII decoder.
     */
    public function validateDngPreviewDateTime(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PREVIEW_DATE_TIME);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('PreviewDateTime must use ASCII type, got %d.', $entry->type),
                1561,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'PreviewDateTime must not be empty.',
                1562,
            );
        }

        // ISO 8601 basic validation: YYYY-MM-DDThh:mm:ss with optional timezone
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $entry->value, $m) !== 1) {
            throw new ParseError(
                sprintf('PreviewDateTime is not a valid ISO 8601 timestamp: %s.', $entry->value),
                1563,
            );
        }

        $month  = (int) $m[1];
        $day    = (int) $m[2];
        $hour   = (int) $m[3];
        $minute = (int) $m[4];
        $second = (int) $m[5];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            throw new ParseError(
                sprintf('PreviewDateTime contains out-of-range date/time components: %s.', $entry->value),
                1564,
            );
        }
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
                    1605,
                );
            }

            $this->extractDngRectangles($activeArea, 'ActiveArea');
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
                    1606,
                );
            }

            $rectangles = $this->extractDngRectangles($maskedAreas, 'MaskedAreas');

            $rectangleCount = count($rectangles);
            for ($leftIndex = 0; $leftIndex < $rectangleCount; ++$leftIndex) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $rectangleCount; ++$rightIndex) {
                    if ($this->dngRectanglesOverlap($rectangles[$leftIndex], $rectangles[$rightIndex])) {
                        throw new ParseError(
                            sprintf(
                                'MaskedAreas rectangles %d and %d overlap.',
                                $leftIndex,
                                $rightIndex,
                            ),
                            1607,
                        );
                    }
                }
            }
        }
    }

    /**
     * Decodes a tag payload into rectangles (top, left, bottom, right).
     *
     * @return list<array{top: int, left: int, bottom: int, right: int}>
     */
    private function extractDngRectangles(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList) {
            throw new ParseError(
                sprintf('%s must decode to a numeric list payload.', $tagName),
                1608,
            );
        }

        $values = [];
        foreach ($entry->value->values as $index => $component) {
            if ($component instanceof UInt64) {
                $values[] = $component->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($component)) {
                $values[] = $component;
            } else {
                if ((float) (int) $component !== $component) {
                    throw new ParseError(
                        sprintf('%s contains a non-integer rectangle component at index %d.', $tagName, $index),
                        1609,
                    );
                }

                $values[] = (int) $component;
            }
        }

        if (count($values) !== $entry->count) {
            throw new ParseError(
                sprintf('%s decoded component count mismatch (expected %d).', $tagName, $entry->count),
                1610,
            );
        }

        if (count($values) % 4 !== 0) {
            throw new ParseError(
                sprintf('%s must contain 4 components per rectangle.', $tagName),
                1611,
            );
        }

        $rectangles = [];
        $counter    = count($values);

        for ($index = 0; $index < $counter; $index += 4) {
            $top    = $values[$index];
            $left   = $values[$index + 1];
            $bottom = $values[$index + 2];
            $right  = $values[$index + 3];

            if (($top < 0) || ($left < 0) || ($bottom < 0) || ($right < 0)) {
                throw new ParseError(
                    sprintf('%s rectangle %d contains negative coordinates.', $tagName, intdiv($index, 4)),
                    1612,
                );
            }

            if (($top >= $bottom) || ($left >= $right)) {
                throw new ParseError(
                    sprintf(
                        '%s rectangle %d must satisfy top < bottom and left < right, got (%d,%d,%d,%d).',
                        $tagName,
                        intdiv($index, 4),
                        $top,
                        $left,
                        $bottom,
                        $right,
                    ),
                    1613,
                );
            }

            $rectangles[] = [
                'top'    => $top,
                'left'   => $left,
                'bottom' => $bottom,
                'right'  => $right,
            ];
        }

        return $rectangles;
    }

    /**
     * Returns true when two rectangles overlap with positive area.
     *
     * @param array{top: int, left: int, bottom: int, right: int} $leftRectangle
     * @param array{top: int, left: int, bottom: int, right: int} $rightRectangle
     */
    private function dngRectanglesOverlap(array $leftRectangle, array $rightRectangle): bool
    {
        return ($leftRectangle['top'] < $rightRectangle['bottom'])
            && ($rightRectangle['top'] < $leftRectangle['bottom'])
            && ($leftRectangle['left'] < $rightRectangle['right'])
            && ($rightRectangle['left'] < $leftRectangle['right']);
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

            [$repeatRows, $repeatCols] = $this->extractDngPositivePairFromNumericList($repeatDim, 'BlackLevelRepeatDim');
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
            $rectangles = $this->extractDngRectangles($activeArea, 'ActiveArea');
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
     * Extracts two strictly positive integer values from a numeric list payload.
     *
     * @return array{0: int, 1: int}
     */
    private function extractDngPositivePairFromNumericList(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList || count($entry->value->values) !== 2) {
            throw new ParseError(
                sprintf('%s must decode to exactly two numeric components.', $tagName),
                1623,
            );
        }

        $components = [];
        foreach ($entry->value->values as $index => $value) {
            if ($value instanceof UInt64) {
                $components[] = $value->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($value)) {
                $components[] = $value;
            } else {
                if ((float) (int) $value !== $value) {
                    throw new ParseError(
                        sprintf('%s component %d must be an integer value.', $tagName, $index),
                        1624,
                    );
                }

                $components[] = (int) $value;
            }
        }

        if (($components[0] <= 0) || ($components[1] <= 0)) {
            throw new ParseError(
                sprintf('%s components must be > 0, got (%d, %d).', $tagName, $components[0], $components[1]),
                1625,
            );
        }

        return [$components[0], $components[1]];
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

            [$scaleH, $scaleV] = $this->extractDngCropScalePair($defaultScale, 'DefaultScale');
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

            [$originH, $originV] = $this->extractDngCropScalePair($defaultCropOrigin, 'DefaultCropOrigin');
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

            [$sizeH, $sizeV] = $this->extractDngCropScalePair($defaultCropSize, 'DefaultCropSize');
            if (($sizeH <= 0.0) || ($sizeV <= 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropSize components must be > 0, got (%.6F, %.6F).',
                        $sizeH,
                        $sizeV,
                    ),
                    1601,
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
        $originalDefaultFinalSize = $this->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
            'OriginalDefaultFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalBestQualityFinalSize = $this->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
            'OriginalBestQualityFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalDefaultCropSize = $this->extractDngOriginalProxySize(
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
        $entry = $ifd->get(DngTag::BEST_QUALITY_SCALE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BestQualityScale must be RATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1641,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRational) {
            throw new ParseError('BestQualityScale must decode to one rational component.', 1642);
        }

        if ($value->denominator <= 0) {
            throw new ParseError('BestQualityScale denominator must be > 0.', 1643);
        }

        if (($value->numerator / $value->denominator) <= 0.0) {
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
        $entry = $ifd->get(DngTag::LINEAR_RESPONSE_LIMIT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'LinearResponseLimit must be RATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1645,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRational) {
            throw new ParseError('LinearResponseLimit must decode to one rational component.', 1646);
        }

        if ($value->denominator <= 0) {
            throw new ParseError('LinearResponseLimit denominator must be > 0.', 1647);
        }

        $limit = $value->numerator / $value->denominator;
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

        if (($photo instanceof IfdEntry) && is_int($photo->value) && ($photo->value !== Photometric::CFA->value)) {
            throw new ParseError(
                sprintf(
                    'BayerGreenSplit requires PhotometricInterpretation=%d, got %d.',
                    Photometric::CFA->value,
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
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
                throw new ParseError(
                    sprintf(
                        '%s must be RATIONAL[1], got type %d count %d.',
                        $rule['name'],
                        $entry->type,
                        $entry->count,
                    ),
                    1662,
                );
            }

            if (!$entry->value instanceof ExifRational) {
                throw new ParseError(
                    sprintf('%s must decode to one rational component.', $rule['name']),
                    1663,
                );
            }

            if ($entry->value->denominator <= 0) {
                throw new ParseError(
                    sprintf('%s denominator must be > 0.', $rule['name']),
                    1664,
                );
            }

            $scalar = $entry->value->numerator / $entry->value->denominator;

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

        $colorPlanes = $this->resolveDngColorPlanes($ifd);
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
        if (
            ($iccEntry->type !== TiffConst::TYPE_UNDEFINED)
            || ($iccEntry->count < 1)
            || !is_string($iccEntry->value)
            || (strlen($iccEntry->value) !== $iccEntry->count)
        ) {
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
        if (
            !$matrixEntry->value instanceof ExifRationalList
            || count($matrixEntry->value->values) !== $matrixEntry->count
        ) {
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

    /**
     * Validates BaselineExposure (0xC62A) DNG layout and scalar sanity.
     *
     * DNG 1.7.1.0 defines BaselineExposure as SRATIONAL[1] EV offset.
     */
    public function validateDngBaselineExposure(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::BASELINE_EXPOSURE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_SRATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BaselineExposure must be SRATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1672,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifRational) {
            throw new ParseError('BaselineExposure must decode to one rational component.', 1673);
        }

        if ($value->denominator === 0) {
            throw new ParseError('BaselineExposure denominator must not be zero.', 1674);
        }

        $scalar = $value->numerator / $value->denominator;

        if (!is_finite($scalar)) {
            throw new ParseError('BaselineExposure must be finite.', 1675);
        }
    }

    /**
     * Validates BaselineNoise and BaselineSharpness scalar tags per DNG 1.7.1.0.
     *
     * Both tags must be RATIONAL[1] with strictly positive finite values.
     *
     * @return void
     */
    public function validateDngBaselineScalars(Ifd $ifd): void
    {
        $tagNames = [
            DngTag::BASELINE_NOISE     => 'BaselineNoise',
            DngTag::BASELINE_SHARPNESS => 'BaselineSharpness',
        ];

        foreach ($tagNames as $tag => $name) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
                throw new ParseError(
                    sprintf(
                        '%s must be RATIONAL[1], got type %d count %d.',
                        $name,
                        $entry->type,
                        $entry->count,
                    ),
                    1654,
                );
            }

            $value = $entry->value;
            if (!$value instanceof ExifRational) {
                throw new ParseError(
                    sprintf('%s must decode to one rational component.', $name),
                    1655,
                );
            }

            if ($value->denominator <= 0) {
                throw new ParseError(
                    sprintf('%s denominator must be > 0.', $name),
                    1656,
                );
            }

            $scalar = $value->numerator / $value->denominator;
            if (!is_finite($scalar) || ($scalar <= 0.0)) {
                throw new ParseError(
                    sprintf('%s must be a positive finite scalar, got %.6F.', $name, $scalar),
                    1657,
                );
            }
        }
    }

    /**
     * Extracts and validates one optional original proxy-size tag.
     *
     * @param list<int> $allowedTypes Allowed TIFF types for this tag.
     *
     * @return array{0: float, 1: float}|null
     */
    private function extractDngOriginalProxySize(
        Ifd $ifd,
        int $tag,
        string $tagName,
        array $allowedTypes,
    ): ?array {
        $entry = $ifd->get($tag);

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if (!in_array($entry->type, $allowedTypes, true) || ($entry->count !== 2)) {
            throw new ParseError(
                sprintf(
                    '%s must use %s with count 2, got type %d count %d.',
                    $tagName,
                    $this->describeAllowedTiffTypes($allowedTypes),
                    $entry->type,
                    $entry->count,
                ),
                1639,
            );
        }

        [$width, $length] = $this->extractDngCropScalePair($entry, $tagName);
        if (($width <= 0.0) || ($length <= 0.0)) {
            throw new ParseError(
                sprintf(
                    '%s components must be > 0, got (%.6F, %.6F).',
                    $tagName,
                    $width,
                    $length,
                ),
                1640,
            );
        }

        return [$width, $length];
    }

    /**
     * Builds a human-readable TIFF type list for validation errors.
     *
     * @param list<int> $types Allowed TIFF type identifiers.
     */
    private function describeAllowedTiffTypes(array $types): string
    {
        $names = [];
        foreach ($types as $type) {
            $names[] = match ($type) {
                TiffConst::TYPE_SHORT    => 'SHORT',
                TiffConst::TYPE_LONG     => 'LONG',
                TiffConst::TYPE_RATIONAL => 'RATIONAL',
                default                  => (string) $type,
            };
        }

        return implode('|', $names);
    }

    /**
     * Extracts two numeric components from crop/scale DNG tags.
     *
     * @return array{0: float, 1: float}
     */
    private function extractDngCropScalePair(IfdEntry $entry, string $tagName): array
    {
        $value = $entry->value;

        if ($value instanceof ExifRationalList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $rational) {
                if ($rational->denominator <= 0) {
                    throw new ParseError(
                        sprintf('%s rational components must have denominator > 0.', $tagName),
                        1603,
                    );
                }

                $values[] = $rational->numerator / $rational->denominator;
            }

            return [$values[0], $values[1]];
        }

        if ($value instanceof ExifNumericList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $values[] = (float) $component->toInt(sprintf('%s component', $tagName));
                } else {
                    $values[] = (float) $component;
                }
            }

            return [$values[0], $values[1]];
        }

        throw new ParseError(
            sprintf('%s must decode to a two-component numeric payload.', $tagName),
            1604,
        );
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
     * Validates DNG depth enum tags per DNG 1.7.1.0.
     *
     * DepthFormat: SHORT[1], allowed {0,1,2}
     * DepthUnits: SHORT[1], allowed {0,1}
     * DepthMeasureType: SHORT[1], allowed {0,1,2}
     */
    public function validateDngDepthEnums(Ifd $ifd): void
    {
        $rules = [
            DngTag::DEPTH_FORMAT       => ['name' => 'DepthFormat', 'allowed' => [0, 1, 2]],
            DngTag::DEPTH_UNITS        => ['name' => 'DepthUnits', 'allowed' => [0, 1]],
            DngTag::DEPTH_MEASURE_TYPE => ['name' => 'DepthMeasureType', 'allowed' => [0, 1, 2]],
        ];

        foreach ($rules as $tag => $config) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value) || !in_array($entry->value, $config['allowed'], true)) {
                throw new ParseError(
                    sprintf(
                        '%s value %d is out of domain per DNG 1.7.1.0.',
                        $config['name'],
                        is_int($entry->value) ? $entry->value : -1,
                    ),
                    1574,
                );
            }
        }
    }

    /**
     * Validates EnhanceParams (0xC7EE) per DNG 1.7.1.0.
     *
     * Must be ASCII type with a non-empty NUL-terminated string.
     */
    public function validateDngEnhanceParams(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ENHANCE_PARAMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('EnhanceParams must use ASCII type, got %d.', $entry->type),
                1575,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'EnhanceParams must not be empty per DNG 1.7.1.0.',
                1576,
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

    /**
     * Validates NoiseReductionApplied (0xC6F7) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1]. Special sentinel 0/0 means unknown.
     * Otherwise the value must be in the range [0.0, 1.0].
     */
    public function validateDngNoiseReductionApplied(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::NOISE_REDUCTION_APPLIED);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!$entry->value instanceof ExifRational) {
            return;
        }

        // 0/0 sentinel means unknown
        if ($entry->value->numerator === 0 && $entry->value->denominator === 0) {
            return;
        }

        if ($entry->value->denominator === 0) {
            throw new ParseError(
                'NoiseReductionApplied has zero denominator without 0/0 sentinel.',
                1581,
            );
        }

        $value = $entry->value->numerator / $entry->value->denominator;

        if ($value < 0.0 || $value > 1.0) {
            throw new ParseError(
                sprintf('NoiseReductionApplied must be in [0.0, 1.0], got %.4f.', $value),
                1582,
            );
        }
    }

    /**
     * Validates ProfileEmbedPolicy (0xC6FD) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value in {0,1,2,3}.
     */
    public function validateDngProfileEmbedPolicy(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_EMBED_POLICY);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!is_int($entry->value) || $entry->value < 0 || $entry->value > 3) {
            throw new ParseError(
                sprintf('ProfileEmbedPolicy value must be 0..3, got %d.', is_int($entry->value) ? $entry->value : -1),
                1583,
            );
        }
    }

    /**
     * Validates CFALayout (0xC617) value domain and version gating per DNG 1.7.1.0.
     *
     * Allowed values are 1..9. Values 6..9 require DNGBackwardVersion >= 1.3.0.0.
     */
    public function validateDngCfaLayoutDomain(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::CFA_LAYOUT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!is_int($entry->value) || $entry->value < 1 || $entry->value > 9) {
            throw new ParseError(
                sprintf('CFALayout value must be 1..9, got %d.', is_int($entry->value) ? $entry->value : -1),
                1584,
            );
        }

        if ($entry->value >= 6) {
            $bwVer = $this->getEffectiveDngBackwardVersion($ifd);

            if ($bwVer !== null && $this->dngVersionLessThan($bwVer, [1, 3, 0, 0])) {
                throw new ParseError(
                    sprintf(
                        'CFALayout value %d requires DNGBackwardVersion >= 1.3.0.0, got %d.%d.%d.%d.',
                        $entry->value,
                        $bwVer[0],
                        $bwVer[1],
                        $bwVer[2],
                        $bwVer[3],
                    ),
                    1585,
                );
            }
        }
    }
}
