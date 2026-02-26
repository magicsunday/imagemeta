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
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises enum conversion helpers for EXIF-backed numeric values.
 * It verifies that numeric strings and ints map to the correct enum cases.
 * The suite covers multiple EXIF enums such as metering, light source, and GPS refs.
 * This keeps enum normalization consistent across structured metadata.
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
     * Maps common numeric EXIF codes to their corresponding enum values.
     * Ensures valid codes return enums while unsupported codes yield null when specified.
     */
    #[Test]
    public function mapsCommonEnumValues(): void
    {
        self::assertSame(Compression::Jpeg, Compression::fromExifValue(6));
        self::assertSame(Compression::JpegNewStyle, Compression::fromExifValue(7));
        self::assertSame(Photometric::WhiteIsZero, Photometric::fromExifValue(0));
        self::assertSame(Photometric::BlackIsZero, Photometric::fromExifValue(1));
        self::assertSame(Photometric::Rgb, Photometric::fromExifValue(2));
        self::assertSame(Photometric::PaletteColor, Photometric::fromExifValue(3));
        self::assertSame(Photometric::TransparencyMask, Photometric::fromExifValue(4));
        self::assertSame(Photometric::Separated, Photometric::fromExifValue(5));
        self::assertSame(Photometric::Ycbcr, Photometric::fromExifValue(6));
        self::assertSame(Photometric::Cielab, Photometric::fromExifValue(8));
        self::assertSame(Photometric::Cfa, Photometric::fromExifValue(32803));
        self::assertSame(Photometric::LinearRaw, Photometric::fromExifValue(34892));
        self::assertSame(Photometric::Depth, Photometric::fromExifValue(51177));
        self::assertSame(Photometric::PhotometricMask, Photometric::fromExifValue(52527));
        self::assertSame(PlanarConfiguration::Chunky, PlanarConfiguration::fromExifValue(1));
        self::assertSame(ResolutionUnit::None, ResolutionUnit::fromExifValue(1));
        self::assertSame(ResolutionUnit::Centimeter, ResolutionUnit::fromExifValue(3));
        self::assertSame(YCbCrPositioning::CoSited, YCbCrPositioning::fromExifValue(2));
        self::assertSame(ExposureMode::AutoBracket, ExposureMode::fromExifValue(2));
        self::assertSame(GainControl::HighGainUp, GainControl::fromExifValue(2));
        self::assertSame(SubjectDistanceRange::Macro, SubjectDistanceRange::fromExifValue(SubjectDistanceRange::Macro->value));
        self::assertSame(FileSource::DigitalCamera, FileSource::fromExifValue(3));
        self::assertSame(SensingMethod::ColorSequentialLinear, SensingMethod::fromExifValue(8));
        self::assertSame(CompositeImage::CapturedWhileShooting, CompositeImage::fromExifValue(3));
        self::assertSame(LightSource::WarmWhiteFluorescent, LightSource::fromExifValue(16));
    }

    /**
     * Supplies numeric values as strings for multiple enum types.
     * Confirms string inputs are normalized and mapped to the expected enums.
     */
    #[Test]
    public function normalizesStringInputs(): void
    {
        self::assertSame(Compression::Jpeg, Compression::fromExifValue('6'));
        self::assertSame(Compression::JpegNewStyle, Compression::fromExifValue('7'));
        self::assertSame(CompositeImage::CapturedWhileShooting, CompositeImage::fromExifValue('3'));
        self::assertSame(LightSource::Unknown, LightSource::fromExifValue('0'));
    }

    /**
     * Uses string payloads for orientation, metering mode, and scene capture codes.
     * Verifies these string codes resolve to the correct enum variants.
     */
    #[Test]
    public function mapsSceneAndMeteringEnumsFromStringPayloads(): void
    {
        self::assertSame(Orientation::RightTop, Orientation::fromExifValue('6'));
        self::assertSame(MeteringMode::CenterWeightedAverage, MeteringMode::fromExifValue('2'));

        // Scene capture codes are frequently stored as strings in manufacturer maker notes.
        self::assertSame(SceneCaptureType::NightScene, SceneCaptureType::fromExifValue('3'));
    }

    /**
     * Checks shooting-condition enums with a mix of valid and invalid values.
     * Ensures supported codes map to enums while invalid codes return null.
     */
    #[Test]
    public function mapsShootingConditionEnums(): void
    {
        self::assertSame(CustomRendered::NormalProcess, CustomRendered::fromExifValue(0));
        self::assertSame(CustomRendered::CustomProcess, CustomRendered::fromExifValue('1'));
        self::assertNull(CustomRendered::fromExifValue(5));

        self::assertSame(WhiteBalance::Auto, WhiteBalance::fromExifValue(0));
        self::assertSame(WhiteBalance::Manual, WhiteBalance::fromExifValue('1'));
        self::assertNull(WhiteBalance::fromExifValue(2));

        self::assertSame(SceneCaptureType::Standard, SceneCaptureType::fromExifValue(0));
        self::assertSame(SceneCaptureType::Portrait, SceneCaptureType::fromExifValue(2));
        self::assertNull(SceneCaptureType::fromExifValue(4));
    }

    /**
     * Passes empty and non-numeric strings into enum mapping helpers.
     * Confirms these inputs are rejected and return null.
     */
    #[Test]
    public function returnsNullForEmptyOrNonNumericStrings(): void
    {
        self::assertNull(Compression::fromExifValue(''));
        self::assertNull(Compression::fromExifValue('foo'));
    }

    /**
     * Uses a vendor-specific FileSource code outside the EXIF-defined range.
     * Ensures the mapping ignores vendor-specific codes and returns null.
     */
    #[Test]
    public function ignoresVendorSpecificFileSource(): void
    {
        self::assertNull(FileSource::fromExifValue(0x8000));
    }

    /**
     * Supplies an out-of-range orientation code.
     * Verifies the mapping returns null for unsupported orientation values.
     */
    #[Test]
    public function returnsNullForOutOfRangeOrientationCodes(): void
    {
        self::assertNull(Orientation::fromExifValue(9));
    }

    /**
     * Uses reserved SubjectDistanceRange codes that should not be mapped.
     * Confirms the mapping returns null for reserved values.
     */
    #[Test]
    public function returnsNullForReservedSubjectDistanceRanges(): void
    {
        self::assertNull(SubjectDistanceRange::fromExifValue(4));
        self::assertNull(SubjectDistanceRange::fromExifValue('9'));
    }

    /**
     * Uses reserved CompositeImage codes outside the defined range.
     * Ensures the mapping rejects them by returning null.
     */
    #[Test]
    public function returnsNullForReservedCompositeImageCodes(): void
    {
        self::assertNull(CompositeImage::fromExifValue(4));
        self::assertNull(CompositeImage::fromExifValue('7'));
    }

    /**
     * Supplies photometric codes outside the TIFF/DNG defined set.
     * Verifies the mapping returns null for these unsupported values.
     */
    #[Test]
    public function returnsNullForUndefinedPhotometricCodes(): void
    {
        self::assertNull(Photometric::fromExifValue(7));
        self::assertNull(Photometric::fromExifValue(9));
        self::assertNull(Photometric::fromExifValue(99));
    }

    /**
     * Maps GPS reference and status codes expressed as strings to enum values.
     * Ensures each EXIF Table 27 code resolves to the correct enum.
     */
    #[Test]
    public function mapsGpsStringBackedEnums(): void
    {
        // GPS Speed Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsSpeedRef::KilometersPerHour, GpsSpeedRef::fromExifValue('K'));
        self::assertSame(GpsSpeedRef::MilesPerHour, GpsSpeedRef::fromExifValue('M'));
        self::assertSame(GpsSpeedRef::Knots, GpsSpeedRef::fromExifValue('N'));

        // GPS Direction Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsDirectionRef::TrueDirection, GpsDirectionRef::fromExifValue('T'));
        self::assertSame(GpsDirectionRef::MagneticDirection, GpsDirectionRef::fromExifValue('M'));

        // GPS Latitude/Longitude Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsLatLonRef::North, GpsLatLonRef::fromExifValue('N'));
        self::assertSame(GpsLatLonRef::South, GpsLatLonRef::fromExifValue('S'));
        self::assertSame(GpsLatLonRef::East, GpsLatLonRef::fromExifValue('E'));
        self::assertSame(GpsLatLonRef::West, GpsLatLonRef::fromExifValue('W'));

        // GPS Status - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsStatus::MeasurementInProgress, GpsStatus::fromExifValue('A'));
        self::assertSame(GpsStatus::MeasurementVoid, GpsStatus::fromExifValue('V'));

        // GPS Distance Reference - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsDistanceRef::Kilometers, GpsDistanceRef::fromExifValue('K'));
        self::assertSame(GpsDistanceRef::Miles, GpsDistanceRef::fromExifValue('M'));
        self::assertSame(GpsDistanceRef::NauticalMiles, GpsDistanceRef::fromExifValue('N'));

        // GPS Measure Mode - EXIF 3.0 §4.6.6 Table 27
        self::assertSame(GpsMeasureMode::TwoDimensional, GpsMeasureMode::fromExifValue('2'));
        self::assertSame(GpsMeasureMode::ThreeDimensional, GpsMeasureMode::fromExifValue('3'));
    }

    /**
     * Provides invalid GPS reference/status codes.
     * Confirms the mapping returns null for unsupported GPS string values.
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

    #[Test]
    public function resolvesExifBackingTypeNameForIntBackedEnums(): void
    {
        $method = new ReflectionMethod(Orientation::class, 'exifBackingTypeName');
        $first  = $method->invoke(null);
        $second = $method->invoke(null);

        self::assertSame('int', $first);
        self::assertSame('int', $second);
    }

    /**
     * Checks rotationDescription strings for all orientation enum values.
     * Ensures each description matches the expected rotation/mirroring semantics.
     */
    #[Test]
    public function mapsOrientationToRotationDescription(): void
    {
        self::assertSame('Unknown', Orientation::Unknown->rotationDescription());
        self::assertSame('Horizontal (normal)', Orientation::TopLeft->rotationDescription());
        self::assertSame('Mirror horizontal', Orientation::TopRight->rotationDescription());
        self::assertSame('Rotate 180', Orientation::BottomRight->rotationDescription());
        self::assertSame('Mirror vertical', Orientation::BottomLeft->rotationDescription());
        self::assertSame('Mirror horizontal and rotate 270 CW', Orientation::LeftTop->rotationDescription());
        self::assertSame('Rotate 90 CW', Orientation::RightTop->rotationDescription());
        self::assertSame('Mirror horizontal and rotate 90 CW', Orientation::RightBottom->rotationDescription());
        self::assertSame('Rotate 270 CW', Orientation::LeftBottom->rotationDescription());
    }

    /**
     * Verifies rotationDegrees for each orientation enum value.
     * Confirms the degrees align with the expected rotation direction.
     */
    #[Test]
    public function mapsOrientationToRotationDegrees(): void
    {
        self::assertSame(0, Orientation::Unknown->rotationDegrees());
        self::assertSame(0, Orientation::TopLeft->rotationDegrees());
        self::assertSame(0, Orientation::TopRight->rotationDegrees());
        self::assertSame(180, Orientation::BottomRight->rotationDegrees());
        self::assertSame(180, Orientation::BottomLeft->rotationDegrees());
        self::assertSame(270, Orientation::LeftTop->rotationDegrees());
        self::assertSame(90, Orientation::RightTop->rotationDegrees());
        self::assertSame(90, Orientation::RightBottom->rotationDegrees());
        self::assertSame(270, Orientation::LeftBottom->rotationDegrees());
    }

    /**
     * Evaluates isMirrored across all orientation enum values.
     * Ensures the mirror flag matches the expected orientation semantics.
     */
    #[Test]
    public function mapsOrientationToMirroredFlag(): void
    {
        self::assertFalse(Orientation::Unknown->isMirrored());
        self::assertFalse(Orientation::TopLeft->isMirrored());
        self::assertTrue(Orientation::TopRight->isMirrored());
        self::assertFalse(Orientation::BottomRight->isMirrored());
        self::assertTrue(Orientation::BottomLeft->isMirrored());
        self::assertTrue(Orientation::LeftTop->isMirrored());
        self::assertFalse(Orientation::RightTop->isMirrored());
        self::assertTrue(Orientation::RightBottom->isMirrored());
        self::assertFalse(Orientation::LeftBottom->isMirrored());
    }
}
