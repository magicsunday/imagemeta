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
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function count;
use function intdiv;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function sprintf;
use function strlen;
use function substr;

/**
 * Validates DNG color profile, tone curve, gain map, and ICC constraints.
 *
 * DNG 1.7.1.0 defines structural and semantic rules for camera profiles,
 * tone curves, hue/saturation maps, look tables, gain maps, ICC profile
 * pairs, and related metadata validated by this class.
 *
 * @phpstan-type GainTableMap2Header = array{
 *     mapPointsV:int,
 *     mapPointsH:int,
 *     mapPointsN:int,
 *     dataType:int,
 *     gamma:float
 * }
 */
final readonly class DngProfileValidator
{
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

    public function __construct(
        private DngValidationSupport $support,
    ) {
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
                2014,
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
                    2016,
                );
            }
        }

        // Check x values are strictly increasing
        $prevX = -1.0;

        for ($i = 0, $n = count($floats); $i < $n; $i += 2) {
            if ($floats[$i] <= $prevX) {
                throw new ParseError(
                    'ProfileToneCurve x coordinates must be strictly increasing per DNG 1.7.1.0.',
                    2015,
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
                    2017,
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
        $this->validateDngDimsLong3(
            $ifd,
            DngTag::PROFILE_HUE_SAT_MAP_DIMS,
            'ProfileHueSatMapDims',
            1511,
            1512,
            1513,
            1514,
        );
    }

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

        $hs = $this->checkedMultiply(
            $hueDivs,
            $satDivs,
            'ProfileHueSatMapData size overflow (H*S).',
            2089,
        );
        $hsv = $this->checkedMultiply(
            $hs,
            $valDivs,
            'ProfileHueSatMapData size overflow (H*S*V).',
            2090,
        );
        $expectedCount = $this->checkedMultiply(
            $hsv,
            3,
            'ProfileHueSatMapData size overflow (H*S*V*3).',
            2091,
        );

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
                    2050,
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
                            2052,
                        );
                    }
                }
            }
        }
    }

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

            $dataType = $this->support->unpackU16(substr($payload, 0, 2));

            if ($dataType === 0) {
                continue;
            }

            if ($dataType !== 1) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X has unknown DataType %d; expected 0 or 1.', $tag, $dataType),
                    2054,
                );
            }

            PayloadGuard::ensureMinimumLength($payload, 6, sprintf('IlluminantData 0x%04X spectral payload', $tag), 1503);

            $numLambda = $this->support->unpackU32(substr($payload, 2, 4));

            if ($numLambda < 2) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X spectral NumLambda must be >= 2, got %d.', $tag, $numLambda),
                    1503,
                );
            }
        }
    }

    /**
     * Validates ProfileLookTableDims (0xC725) per DNG 1.7.1.0.
     *
     * Must be LONG[3]: HueDivisions >= 1, SaturationDivisions >= 2, ValueDivisions >= 1.
     */
    public function validateDngProfileLookTableDims(Ifd $ifd): void
    {
        $this->validateDngDimsLong3(
            $ifd,
            DngTag::PROFILE_LOOK_TABLE_DIMS,
            'ProfileLookTableDims',
            1547,
            1548,
            1549,
            1550,
        );
    }

    /**
     * Validates DNG LONG[3] dimension tags (Hue/Saturation/Value divisions).
     */
    private function validateDngDimsLong3(
        Ifd $ifd,
        int $tag,
        string $tagName,
        int $typeErrCode,
        int $hueErrCode,
        int $satErrCode,
        int $valErrCode,
    ): void {
        $entry = $ifd->get($tag);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 3) {
            throw new ParseError(
                sprintf('%s must be LONG[3], got type %d count %d.', $tagName, $entry->type, $entry->count),
                $typeErrCode,
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
                sprintf('%s HueDivisions must be >= 1, got %d.', $tagName, $hueDivs),
                $hueErrCode,
            );
        }

        if ($satDivs < 2) {
            throw new ParseError(
                sprintf('%s SaturationDivisions must be >= 2, got %d.', $tagName, $satDivs),
                $satErrCode,
            );
        }

        if ($valDivs < 1) {
            throw new ParseError(
                sprintf('%s ValueDivisions must be >= 1, got %d.', $tagName, $valDivs),
                $valErrCode,
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

        $hs = $this->checkedMultiply(
            $hueDivs,
            $satDivs,
            'ProfileLookTableData size overflow (H*S).',
            2092,
        );
        $hsv = $this->checkedMultiply(
            $hs,
            $valDivs,
            'ProfileLookTableData size overflow (H*S*V).',
            2093,
        );
        $expectedCount = $this->checkedMultiply(
            $hsv,
            3,
            'ProfileLookTableData size overflow (H*S*V*3).',
            2094,
        );

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
     * Validates BaselineExposure (0xC62A) DNG layout and scalar sanity.
     *
     * DNG 1.7.1.0 defines BaselineExposure as SRATIONAL[1] EV offset.
     */
    public function validateDngBaselineExposure(Ifd $ifd): void
    {
        $scalar = $this->support->extractRationalScalar(
            $ifd,
            DngTag::BASELINE_EXPOSURE,
            'BaselineExposure',
            TiffConst::TYPE_SRATIONAL,
            'SRATIONAL',
            1672,
            1673,
            1674,
            'not be zero',
            false,
        );

        if ($scalar === null) {
            return;
        }

        if (!is_finite($scalar)) {
            throw new ParseError('BaselineExposure must be finite.', 1675);
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
                2048,
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
                    2045,
                );
            }
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
                2055,
            );
        }

        $version = $this->support->unpackU16(substr($payload, 0, 2));

        if ($version !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange Version must be 1, got %d.', $version),
                2057,
            );
        }

        $dynamicRange = $this->support->unpackU16(substr($payload, 2, 2));

        if ($dynamicRange !== 0 && $dynamicRange !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange DynamicRange must be 0 or 1, got %d.', $dynamicRange),
                1507,
            );
        }

        if ($dynamicRange === 0) {
            $hint = $this->support->unpackFloat(substr($payload, 4, 4));

            if ($hint > 1.0) {
                throw new ParseError(
                    sprintf('SDR ProfileDynamicRange HintMaxOutputValue must be <= 1.0, got %g.', $hint),
                    2060,
                );
            }
        }
    }

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
        PayloadGuard::ensureMinimumLength($payload, 80, 'ProfileGainTableMap2 payload', 1516);
        $header = $this->decodeProfileGainTableMap2Header($payload);

        $bytesPerElement = $this->validateProfileGainTableMap2Header($header);
        $this->validateProfileGainTableMap2Length(strlen($payload), $header, $bytesPerElement);
    }

    /**
     * Decodes ProfileGainTableMap2 fixed header fields used for staged validation.
     *
     * @param string $payload ProfileGainTableMap2 raw payload bytes.
     *
     * @return GainTableMap2Header
     */
    private function decodeProfileGainTableMap2Header(string $payload): array
    {
        // DNG 1.7.1.0 ProfileGainTableMap2 80-byte header layout:
        // Bytes  0– 3: MapPointsV (uint32)
        // Bytes  4– 7: MapPointsH (uint32)
        // Bytes  8–39: MapSpacing[V,H] (8 doubles)
        // Bytes 40–43: MapPointsN (uint32)
        // Bytes 44–63: MapGamma/reserved (5 floats)
        // Bytes 64–67: DataType (uint32, 0=float32/1=float16/2=uint8/3=uint16)
        // Bytes 68–71: Gamma (float32, 0.25–4.0)
        // Bytes 72–79: reserved
        return [
            'mapPointsV' => $this->support->unpackU32(substr($payload, 0, 4)),
            'mapPointsH' => $this->support->unpackU32(substr($payload, 4, 4)),
            'mapPointsN' => $this->support->unpackU32(substr($payload, 40, 4)),
            'dataType'   => $this->support->unpackU32(substr($payload, 64, 4)),
            'gamma'      => $this->support->unpackFloat(substr($payload, 68, 4)),
        ];
    }

    /**
     * Validates ProfileGainTableMap2 scalar header values and returns bytes per element.
     *
     * @param GainTableMap2Header $header
     */
    private function validateProfileGainTableMap2Header(array $header): int
    {
        if (!isset(self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$header['dataType']])) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 DataType must be 0..3, got %d.', $header['dataType']),
                1517,
            );
        }

        if ($header['gamma'] < 0.25 || $header['gamma'] > 4.0) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 Gamma must be 0.25..4.0, got %g.', $header['gamma']),
                1518,
            );
        }

        return self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$header['dataType']];
    }

    /**
     * Validates ProfileGainTableMap2 payload length against declared map dimensions.
     *
     * @param GainTableMap2Header $header
     */
    private function validateProfileGainTableMap2Length(int $length, array $header, int $bytesPerElement): void
    {
        $expectedLength = 80 + ($bytesPerElement * $header['mapPointsV'] * $header['mapPointsH'] * $header['mapPointsN']);

        if ($length !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap2 count mismatch: expected %d (80 + %d*%d*%d*%d), got %d.',
                    $expectedLength,
                    $bytesPerElement,
                    $header['mapPointsV'],
                    $header['mapPointsH'],
                    $header['mapPointsN'],
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
        PayloadGuard::ensureMinimumLength($payload, 64, 'ProfileGainTableMap payload', 1686);
        $length = strlen($payload);

        // DNG 1.7.1.0 ProfileGainTableMap (legacy) 64-byte header layout:
        // Bytes  0– 3: MapPointsV (uint32)
        // Bytes  4– 7: MapPointsH (uint32)
        // Bytes  8–15: MapSpacingV (double)
        // Bytes 16–23: MapSpacingH (double)
        // Bytes 24–31: MapOriginV (double)
        // Bytes 32–39: MapOriginH (double)
        // Bytes 40–43: MapPointsN (uint32)
        // Bytes 44–47: MapGamma (float32)
        // Bytes 48–51: reserved float32
        // Bytes 52–55: reserved float32
        // Bytes 56–59: reserved float32
        // Bytes 60–63: reserved float32
        $mapPointsV = $this->support->unpackU32(substr($payload, 0, 4));
        $mapPointsH = $this->support->unpackU32(substr($payload, 4, 4));
        $mapPointsN = $this->support->unpackU32(substr($payload, 40, 4));

        // Decode and validate fixed header scalar fields to enforce binary layout.
        $headerScalars = [
            $this->support->unpackDouble(substr($payload, 8, 8)),   // MapSpacingV
            $this->support->unpackDouble(substr($payload, 16, 8)),  // MapSpacingH
            $this->support->unpackDouble(substr($payload, 24, 8)),  // MapOriginV
            $this->support->unpackDouble(substr($payload, 32, 8)),  // MapOriginH
            $this->support->unpackFloat(substr($payload, 44, 4)),   // MapGamma
            $this->support->unpackFloat(substr($payload, 48, 4)),   // reserved
            $this->support->unpackFloat(substr($payload, 52, 4)),   // reserved
            $this->support->unpackFloat(substr($payload, 56, 4)),   // reserved
            $this->support->unpackFloat(substr($payload, 60, 4)),   // reserved
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

        $vh = $this->checkedMultiply(
            $mapPointsV,
            $mapPointsH,
            'ProfileGainTableMap size multiplication overflow (V*H).',
            1689,
        );
        $entryCount = $this->checkedMultiply(
            $vh,
            $mapPointsN,
            'ProfileGainTableMap size multiplication overflow (V*H*N).',
            1690,
        );

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
            $gain = $this->support->unpackFloat(substr($payload, $offset, 4));
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
     * Multiplies two integers with overflow checking against PHP_INT_MAX.
     */
    private function checkedMultiply(int $left, int $right, string $overflowMessage, int $code): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        if ($left > intdiv(PHP_INT_MAX, $right)) {
            throw new ParseError($overflowMessage, $code);
        }

        return $left * $right;
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

        $this->validateExtraCameraProfilesEntry($entry);

        $profileOffsets = $this->extractDngExtraCameraProfileOffsets($entry);
        if (count($profileOffsets) !== $entry->count) {
            throw new ParseError(
                'ExtraCameraProfiles count does not match the number of decoded offsets.',
                1588,
            );
        }

        $buffer   = $this->support->buffer();
        $blobSize = $buffer->size();

        foreach ($profileOffsets as $profileIndex => $profileOffset) {
            $this->validateExtraCameraProfileRecord($profileIndex, $profileOffset, $blobSize);
        }
    }

    /**
     * Validates the ExtraCameraProfiles tag-level type/count constraints.
     */
    private function validateExtraCameraProfilesEntry(IfdEntry $entry): void
    {
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
    }

    /**
     * Validates one ExtraCameraProfiles offset target and its embedded profile header.
     */
    private function validateExtraCameraProfileRecord(int $profileIndex, int $profileOffset, int $blobSize): void
    {
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

        $buffer           = $this->support->buffer();
        $cursorBeforeRead = $buffer->tell();
        $buffer->seek($profileOffset);
        $profileHeader = $buffer->read(8);
        $buffer->seek($cursorBeforeRead);

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

    /**
     * Normalizes ExtraCameraProfiles offset values into an integer list.
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
}
