<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value\Enum;

use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Enum mapping tests.
 */
#[CoversClass(Photometric::class)]
#[CoversClass(ResolutionUnit::class)]
#[CoversClass(ExposureMode::class)]
#[CoversClass(SubjectDistanceRange::class)]
#[CoversClass(SensingMethod::class)]
#[CoversClass(LightSource::class)]
#[CoversClass(MeteringMode::class)]
#[CoversClass(GpsSpeedRef::class)]
#[CoversClass(GpsDirectionRef::class)]
#[CoversClass(GpsLatLonRef::class)]
#[CoversClass(GpsStatus::class)]
#[CoversClass(GpsDistanceRef::class)]
#[CoversClass(GpsMeasureMode::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[CoversClass(Compression::class)]
#[CoversClass(CustomRendered::class)]
#[CoversClass(SceneCaptureType::class)]
#[CoversClass(WhiteBalance::class)]
final class EnumMappingTest extends TestCase
{
    /**
     * Ensures enums convert canonical integer encodings into typed values.
     */
    #[Test]
    public function mapsCommonEnumValues(): void
    {
        self::assertSame(Compression::JPEG, Compression::fromExifValue(6));
        self::assertSame(Compression::JPEG_NEW_STYLE, Compression::fromExifValue(7));
        self::assertSame(Photometric::YCBCR, Photometric::fromExifValue(6));
        self::assertSame(PlanarConfiguration::CHUNKY, PlanarConfiguration::fromExifValue(1));
        self::assertSame(ResolutionUnit::CENTIMETER, ResolutionUnit::fromExifValue(3));
        self::assertNull(ResolutionUnit::fromExifValue(1));
        self::assertSame(YCbCrPositioning::CO_SITED, YCbCrPositioning::fromExifValue(2));
        self::assertSame(ExposureMode::AUTO_BRACKET, ExposureMode::fromExifValue(2));
        self::assertSame(GainControl::HIGH_GAIN_UP, GainControl::fromExifValue(2));
        self::assertSame(SubjectDistanceRange::MACRO, SubjectDistanceRange::fromExifValue(SubjectDistanceRange::MACRO->value));
        self::assertSame(FileSource::DIGITAL_CAMERA, FileSource::fromExifValue(3));
        self::assertSame(SensingMethod::COLOR_SEQUENTIAL_LINEAR, SensingMethod::fromExifValue(8));
        self::assertSame(CompositeImage::CAPTURED_WHILE_SHOOTING, CompositeImage::fromExifValue(3));
        self::assertSame(LightSource::WARM_WHITE_FLUORESCENT, LightSource::fromExifValue(16));
    }

    /**
     * Normalises numeric-string payloads emitted by some encoders.
     */
    #[Test]
    public function normalizesStringInputs(): void
    {
        self::assertSame(Compression::JPEG, Compression::fromExifValue('6'));
        self::assertSame(Compression::JPEG_NEW_STYLE, Compression::fromExifValue('7'));
        self::assertSame(CompositeImage::CAPTURED_WHILE_SHOOTING, CompositeImage::fromExifValue('3'));
        self::assertSame(LightSource::UNKNOWN, LightSource::fromExifValue('0'));
    }

    /**
     * Converts camera orientation, metering and scene capture enums when delivered as numeric strings.
     */
    #[Test]
    public function mapsSceneAndMeteringEnumsFromStringPayloads(): void
    {
        self::assertSame(Orientation::RIGHT_TOP, Orientation::fromExifValue('6'));
        self::assertSame(MeteringMode::CENTER_WEIGHTED_AVERAGE, MeteringMode::fromExifValue('2'));

        // Scene capture codes are frequently stored as strings in manufacturer maker notes.
        self::assertSame(SceneCaptureType::NIGHT_SCENE, SceneCaptureType::fromExifValue('3'));
    }

    /**
     * Maps shooting-condition enums and rejects reserved payloads.
     */
    #[Test]
    public function mapsShootingConditionEnums(): void
    {
        self::assertSame(CustomRendered::NORMAL_PROCESS, CustomRendered::fromExifValue(0));
        self::assertSame(CustomRendered::CUSTOM_PROCESS, CustomRendered::fromExifValue('1'));
        self::assertNull(CustomRendered::fromExifValue(5));

        self::assertSame(WhiteBalance::AUTO, WhiteBalance::fromExifValue(0));
        self::assertSame(WhiteBalance::MANUAL, WhiteBalance::fromExifValue('1'));
        self::assertNull(WhiteBalance::fromExifValue(2));

        self::assertSame(SceneCaptureType::STANDARD, SceneCaptureType::fromExifValue(0));
        self::assertSame(SceneCaptureType::PORTRAIT, SceneCaptureType::fromExifValue(2));
        self::assertNull(SceneCaptureType::fromExifValue(4));
    }

    /**
     * Returns null for empty or non-numeric payloads that cannot be mapped to an enum.
     */
    #[Test]
    public function returnsNullForEmptyOrNonNumericStrings(): void
    {
        self::assertNull(Compression::fromExifValue(''));
        self::assertNull(Compression::fromExifValue('foo'));
    }

    /**
     * Ignores vendor specific file source values outside of the EXIF specification.
     */
    #[Test]
    public function ignoresVendorSpecificFileSource(): void
    {
        self::assertNull(FileSource::fromExifValue(0x8000));
    }

    /**
     * Rejects orientation codes that fall outside the EXIF defined range.
     */
    #[Test]
    public function returnsNullForOutOfRangeOrientationCodes(): void
    {
        self::assertNull(Orientation::fromExifValue(9));
    }

    /**
     * Rejects reserved subject distance range codes outside the defined set.
     */
    #[Test]
    public function returnsNullForReservedSubjectDistanceRanges(): void
    {
        self::assertNull(SubjectDistanceRange::fromExifValue(4));
        self::assertNull(SubjectDistanceRange::fromExifValue('9'));
    }

    /**
     * Rejects composite image codes outside the enumerated EXIF range.
     */
    #[Test]
    public function returnsNullForReservedCompositeImageCodes(): void
    {
        self::assertNull(CompositeImage::fromExifValue(4));
        self::assertNull(CompositeImage::fromExifValue('7'));
    }

    /**
     * Rejects reserved photometric interpretations outside the EXIF allowed set.
     *
     * EXIF 3.0 §4.6.5.1.5 limits PhotometricInterpretation to RGB (2) and YCbCr (6).
     */
    #[Test]
    public function returnsNullForReservedPhotometricCodes(): void
    {
        self::assertNull(Photometric::fromExifValue(0));
        self::assertNull(Photometric::fromExifValue(3));
        self::assertNull(Photometric::fromExifValue(8));
    }

    /**
     * Maps GPS string-backed enums correctly.
     *
     * EXIF 3.0 §4.6.6 Table 27 defines GPS reference tags using single-character
     * string values (e.g., 'K', 'M', 'N' for speed reference, 'T', 'M' for direction).
     */
    #[Test]
    public function mapsGpsStringBackedEnums(): void
    {
        // GPS Speed Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsSpeedRef::KILOMETERS_PER_HOUR, GpsSpeedRef::fromExifValue('K'));
        self::assertSame(GpsSpeedRef::MILES_PER_HOUR, GpsSpeedRef::fromExifValue('M'));
        self::assertSame(GpsSpeedRef::KNOTS, GpsSpeedRef::fromExifValue('N'));

        // GPS Direction Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsDirectionRef::TRUE_DIRECTION, GpsDirectionRef::fromExifValue('T'));
        self::assertSame(GpsDirectionRef::MAGNETIC_DIRECTION, GpsDirectionRef::fromExifValue('M'));

        // GPS Latitude/Longitude Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsLatLonRef::NORTH, GpsLatLonRef::fromExifValue('N'));
        self::assertSame(GpsLatLonRef::SOUTH, GpsLatLonRef::fromExifValue('S'));
        self::assertSame(GpsLatLonRef::EAST, GpsLatLonRef::fromExifValue('E'));
        self::assertSame(GpsLatLonRef::WEST, GpsLatLonRef::fromExifValue('W'));

        // GPS Status - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsStatus::MEASUREMENT_IN_PROGRESS, GpsStatus::fromExifValue('A'));
        self::assertSame(GpsStatus::MEASUREMENT_VOID, GpsStatus::fromExifValue('V'));

        // GPS Distance Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsDistanceRef::KILOMETERS, GpsDistanceRef::fromExifValue('K'));
        self::assertSame(GpsDistanceRef::MILES, GpsDistanceRef::fromExifValue('M'));
        self::assertSame(GpsDistanceRef::NAUTICAL_MILES, GpsDistanceRef::fromExifValue('N'));

        // GPS Measure Mode - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsMeasureMode::TWO_DIMENSIONAL, GpsMeasureMode::fromExifValue('2'));
        self::assertSame(GpsMeasureMode::THREE_DIMENSIONAL, GpsMeasureMode::fromExifValue('3'));
    }

    /**
     * Returns null for invalid GPS string values.
     */
    #[Test]
    public function returnsNullForInvalidGpsStrings(): void
    {
        self::assertNull(GpsSpeedRef::fromExifValue('X'));
        self::assertNull(GpsDirectionRef::fromExifValue('Z'));
        self::assertNull(GpsLatLonRef::fromExifValue('Q'));
        self::assertNull(GpsStatus::fromExifValue('B'));
        self::assertNull(GpsDistanceRef::fromExifValue('Y'));
        self::assertNull(GpsMeasureMode::fromExifValue('5'));
    }

    /**
     * Verifies orientation rotation descriptions match ExifTool output.
     *
     * EXIF 3.0 §4.6.5.1.6 defines eight orientation states; these descriptions
     * align with the common ExifTool output format.
     */
    #[Test]
    public function mapsOrientationToRotationDescription(): void
    {
        self::assertSame('Unknown', Orientation::UNKNOWN->rotationDescription());
        self::assertSame('Horizontal (normal)', Orientation::TOP_LEFT->rotationDescription());
        self::assertSame('Mirror horizontal', Orientation::TOP_RIGHT->rotationDescription());
        self::assertSame('Rotate 180', Orientation::BOTTOM_RIGHT->rotationDescription());
        self::assertSame('Mirror vertical', Orientation::BOTTOM_LEFT->rotationDescription());
        self::assertSame('Mirror horizontal and rotate 270 CW', Orientation::LEFT_TOP->rotationDescription());
        self::assertSame('Rotate 90 CW', Orientation::RIGHT_TOP->rotationDescription());
        self::assertSame('Mirror horizontal and rotate 90 CW', Orientation::RIGHT_BOTTOM->rotationDescription());
        self::assertSame('Rotate 270 CW', Orientation::LEFT_BOTTOM->rotationDescription());
    }

    /**
     * Verifies orientation rotation degrees are calculated correctly.
     */
    #[Test]
    public function mapsOrientationToRotationDegrees(): void
    {
        self::assertSame(0, Orientation::UNKNOWN->rotationDegrees());
        self::assertSame(0, Orientation::TOP_LEFT->rotationDegrees());
        self::assertSame(0, Orientation::TOP_RIGHT->rotationDegrees());
        self::assertSame(180, Orientation::BOTTOM_RIGHT->rotationDegrees());
        self::assertSame(0, Orientation::BOTTOM_LEFT->rotationDegrees());
        self::assertSame(180, Orientation::LEFT_TOP->rotationDegrees());
        self::assertSame(90, Orientation::RIGHT_TOP->rotationDegrees());
        self::assertSame(90, Orientation::RIGHT_BOTTOM->rotationDegrees());
        self::assertSame(270, Orientation::LEFT_BOTTOM->rotationDegrees());
    }

    /**
     * Verifies orientation mirrored flag is set correctly.
     */
    #[Test]
    public function mapsOrientationToMirroredFlag(): void
    {
        self::assertFalse(Orientation::UNKNOWN->isMirrored());
        self::assertFalse(Orientation::TOP_LEFT->isMirrored());
        self::assertTrue(Orientation::TOP_RIGHT->isMirrored());
        self::assertFalse(Orientation::BOTTOM_RIGHT->isMirrored());
        self::assertTrue(Orientation::BOTTOM_LEFT->isMirrored());
        self::assertTrue(Orientation::LEFT_TOP->isMirrored());
        self::assertFalse(Orientation::RIGHT_TOP->isMirrored());
        self::assertTrue(Orientation::RIGHT_BOTTOM->isMirrored());
        self::assertFalse(Orientation::LEFT_BOTTOM->isMirrored());
    }
}
