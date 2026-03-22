<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngProfileValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function pack;
use function str_repeat;

/**
 * Verifies DNG color profile, tone curve, and related validation logic.
 *
 * @internal
 */
#[CoversClass(DngProfileValidator::class)]
#[UsesClass(DngValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
final class DngProfileValidatorTest extends TestCase
{
    private DngProfileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $buffer          = new MemoryBuffer(str_repeat("\0", 256));
        $support         = new DngValidationSupport(Endian::Little, $buffer);
        $this->validator = new DngProfileValidator($support);
    }

    #[Test]
    public function usesSingleParameterizedLong3DimensionValidator(): void
    {
        $reflection = new ReflectionClass(DngProfileValidator::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('validateDngDimsLong3', $methods);
    }

    // --- ProfileToneCurve ---

    #[Test]
    public function acceptsValidSdrProfileToneCurve(): void
    {
        $ifd = new Ifd([
            DngTag::PROFILE_TONE_CURVE => new IfdEntry(
                DngTag::PROFILE_TONE_CURVE,
                TiffConst::TYPE_FLOAT,
                4,
                new ExifNumericList([0.0, 0.0, 1.0, 1.0]),
            ),
        ]);

        $this->validator->validateDngProfileToneCurve($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsProfileToneCurveWithOddCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileToneCurve FLOAT count must be even');

        $ifd = new Ifd([
            DngTag::PROFILE_TONE_CURVE => new IfdEntry(
                DngTag::PROFILE_TONE_CURVE,
                TiffConst::TYPE_FLOAT,
                3,
                new ExifNumericList([0.0, 0.0, 0.5]),
            ),
        ]);

        $this->validator->validateDngProfileToneCurve($ifd);
    }

    #[Test]
    public function rejectsProfileToneCurveWithValueOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileToneCurve values must be finite floats in [0.0, 1.0]');

        $ifd = new Ifd([
            DngTag::PROFILE_TONE_CURVE => new IfdEntry(
                DngTag::PROFILE_TONE_CURVE,
                TiffConst::TYPE_FLOAT,
                4,
                new ExifNumericList([0.0, 0.0, 1.0, 2.0]),
            ),
        ]);

        $this->validator->validateDngProfileToneCurve($ifd);
    }

    #[Test]
    public function rejectsProfileToneCurveWithNonIncreasingX(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileToneCurve x coordinates must be strictly increasing');

        $ifd = new Ifd([
            DngTag::PROFILE_TONE_CURVE => new IfdEntry(
                DngTag::PROFILE_TONE_CURVE,
                TiffConst::TYPE_FLOAT,
                6,
                new ExifNumericList([0.0, 0.0, 0.5, 0.5, 0.3, 1.0]),
            ),
        ]);

        $this->validator->validateDngProfileToneCurve($ifd);
    }

    // --- HueSatMapDims ---

    #[Test]
    public function acceptsValidHueSatMapDims(): void
    {
        $ifd = new Ifd([
            DngTag::PROFILE_HUE_SAT_MAP_DIMS => new IfdEntry(
                DngTag::PROFILE_HUE_SAT_MAP_DIMS,
                TiffConst::TYPE_LONG,
                3,
                new ExifNumericList([36, 8, 4]),
            ),
        ]);

        $this->validator->validateDngHueSatMapDims($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsHueSatMapDimsSatDivisionsLessThanTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileHueSatMapDims SaturationDivisions must be >= 2');

        $ifd = new Ifd([
            DngTag::PROFILE_HUE_SAT_MAP_DIMS => new IfdEntry(
                DngTag::PROFILE_HUE_SAT_MAP_DIMS,
                TiffConst::TYPE_LONG,
                3,
                new ExifNumericList([36, 1, 4]),
            ),
        ]);

        $this->validator->validateDngHueSatMapDims($ifd);
    }

    #[Test]
    public function rejectsHueSatMapDataDimensionOverflow(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2089);

        $ifd = new Ifd([
            DngTag::PROFILE_HUE_SAT_MAP_DIMS => new IfdEntry(
                DngTag::PROFILE_HUE_SAT_MAP_DIMS,
                TiffConst::TYPE_LONG,
                3,
                new ExifNumericList([PHP_INT_MAX, 2, 1]),
            ),
            DngTag::PROFILE_HUE_SAT_MAP_DATA_1 => new IfdEntry(
                DngTag::PROFILE_HUE_SAT_MAP_DATA_1,
                TiffConst::TYPE_FLOAT,
                1,
                new ExifNumericList([1.0]),
            ),
        ]);

        $this->validator->validateDngHueSatMapData($ifd);
    }

    #[Test]
    public function rejectsProfileLookTableDataDimensionOverflow(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2092);

        $ifd = new Ifd([
            DngTag::PROFILE_LOOK_TABLE_DIMS => new IfdEntry(
                DngTag::PROFILE_LOOK_TABLE_DIMS,
                TiffConst::TYPE_LONG,
                3,
                new ExifNumericList([PHP_INT_MAX, 2, 1]),
            ),
            DngTag::PROFILE_LOOK_TABLE_DATA => new IfdEntry(
                DngTag::PROFILE_LOOK_TABLE_DATA,
                TiffConst::TYPE_FLOAT,
                1,
                new ExifNumericList([1.0]),
            ),
        ]);

        $this->validator->validateDngProfileLookTableData($ifd);
    }

    // --- BaselineExposure ---

    #[Test]
    public function acceptsValidBaselineExposure(): void
    {
        $ifd = new Ifd([
            DngTag::BASELINE_EXPOSURE => new IfdEntry(
                DngTag::BASELINE_EXPOSURE,
                TiffConst::TYPE_SRATIONAL,
                1,
                new ExifRational(0, 1),
            ),
        ]);

        $this->validator->validateDngBaselineExposure($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsBaselineExposureWithZeroDenominator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('BaselineExposure denominator must not be zero');

        $ifd = new Ifd([
            DngTag::BASELINE_EXPOSURE => new IfdEntry(
                DngTag::BASELINE_EXPOSURE,
                TiffConst::TYPE_SRATIONAL,
                1,
                new ExifRational(1, 0),
            ),
        ]);

        $this->validator->validateDngBaselineExposure($ifd);
    }

    // --- ProfileEmbedPolicy ---

    #[Test]
    public function acceptsValidProfileEmbedPolicy(): void
    {
        $ifd = new Ifd([
            DngTag::PROFILE_EMBED_POLICY => new IfdEntry(
                DngTag::PROFILE_EMBED_POLICY,
                TiffConst::TYPE_LONG,
                1,
                2,
            ),
        ]);

        $this->validator->validateDngProfileEmbedPolicy($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsProfileEmbedPolicyOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileEmbedPolicy value must be 0..3');

        $ifd = new Ifd([
            DngTag::PROFILE_EMBED_POLICY => new IfdEntry(
                DngTag::PROFILE_EMBED_POLICY,
                TiffConst::TYPE_LONG,
                1,
                5,
            ),
        ]);

        $this->validator->validateDngProfileEmbedPolicy($ifd);
    }

    // --- NoiseProfile ---

    #[Test]
    public function acceptsValidNoiseProfile(): void
    {
        $ifd = new Ifd([
            DngTag::NOISE_PROFILE => new IfdEntry(
                DngTag::NOISE_PROFILE,
                TiffConst::TYPE_DOUBLE,
                2,
                new ExifNumericList([0.001, 0.0005]),
            ),
        ]);

        $this->validator->validateDngNoiseProfile($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsNoiseProfileWithOddCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NoiseProfile count must be even');

        $ifd = new Ifd([
            DngTag::NOISE_PROFILE => new IfdEntry(
                DngTag::NOISE_PROFILE,
                TiffConst::TYPE_DOUBLE,
                3,
                new ExifNumericList([0.001, 0.0005, 0.002]),
            ),
        ]);

        $this->validator->validateDngNoiseProfile($ifd);
    }

    #[Test]
    public function rejectsNoiseProfileWithNegativeS(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NoiseProfile S_0 must be > 0');

        $ifd = new Ifd([
            DngTag::NOISE_PROFILE => new IfdEntry(
                DngTag::NOISE_PROFILE,
                TiffConst::TYPE_DOUBLE,
                2,
                new ExifNumericList([-0.001, 0.0005]),
            ),
        ]);

        $this->validator->validateDngNoiseProfile($ifd);
    }

    // --- ProfileDynamicRange ---

    #[Test]
    public function acceptsValidSdrProfileDynamicRange(): void
    {
        // Build an 8-byte LE payload: Version=1, DynamicRange=0 (SDR), HintMaxOutputValue=1.0
        $payload = pack('v', 1) . pack('v', 0) . pack('g', 1.0);

        $ifd = new Ifd([
            DngTag::PROFILE_DYNAMIC_RANGE => new IfdEntry(
                DngTag::PROFILE_DYNAMIC_RANGE,
                TiffConst::TYPE_UNDEFINED,
                8,
                $payload,
            ),
        ]);

        $this->validator->validateDngProfileDynamicRange($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsProfileDynamicRangeWrongPayloadSize(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileDynamicRange payload must be 8 bytes');

        $ifd = new Ifd([
            DngTag::PROFILE_DYNAMIC_RANGE => new IfdEntry(
                DngTag::PROFILE_DYNAMIC_RANGE,
                TiffConst::TYPE_UNDEFINED,
                4,
                "\x01\x00\x00\x00",
            ),
        ]);

        $this->validator->validateDngProfileDynamicRange($ifd);
    }

    #[Test]
    public function rejectsProfileDynamicRangeInvalidVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileDynamicRange Version must be 1');

        $payload = pack('v', 2) . pack('v', 0) . pack('g', 1.0);

        $ifd = new Ifd([
            DngTag::PROFILE_DYNAMIC_RANGE => new IfdEntry(
                DngTag::PROFILE_DYNAMIC_RANGE,
                TiffConst::TYPE_UNDEFINED,
                8,
                $payload,
            ),
        ]);

        $this->validator->validateDngProfileDynamicRange($ifd);
    }

    // --- MultiProfileName ---

    #[Test]
    public function acceptsSingleProfileWithoutName(): void
    {
        $ifd0 = new Ifd([
            DngTag::COLOR_MATRIX_1 => new IfdEntry(DngTag::COLOR_MATRIX_1, TiffConst::TYPE_SRATIONAL, 9, 0),
        ]);

        $this->validator->validateDngMultiProfileName($ifd0, []);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMultipleProfilesWithoutNames(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ProfileName is required for camera profile');

        $ifd0 = new Ifd([
            DngTag::COLOR_MATRIX_1 => new IfdEntry(DngTag::COLOR_MATRIX_1, TiffConst::TYPE_SRATIONAL, 9, 0),
        ]);

        $ifd1 = new Ifd([
            DngTag::COLOR_MATRIX_1 => new IfdEntry(DngTag::COLOR_MATRIX_1, TiffConst::TYPE_SRATIONAL, 9, 0),
        ]);

        $this->validator->validateDngMultiProfileName($ifd0, [$ifd1]);
    }
}
