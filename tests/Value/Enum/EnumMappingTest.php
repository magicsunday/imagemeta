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
     *
     * @return void
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
     * Supplies numeric values as strings for multiple enum types.
     * Confirms string inputs are normalized and mapped to the expected enums.
     *
     * @return void
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
     * Uses string payloads for orientation, metering mode, and scene capture codes.
     * Verifies these string codes resolve to the correct enum variants.
     *
     * @return void
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
     * Checks shooting-condition enums with a mix of valid and invalid values.
     * Ensures supported codes map to enums while invalid codes return null.
     *
     * @return void
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
     * Passes empty and non-numeric strings into enum mapping helpers.
     * Confirms these inputs are rejected and return null.
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function ignoresVendorSpecificFileSource(): void
    {
        self::assertNull(FileSource::fromExifValue(0x8000));
    }

    /**
     * Supplies an out-of-range orientation code.
     * Verifies the mapping returns null for unsupported orientation values.
     *
     * @return void
     */
    #[Test]
    public function returnsNullForOutOfRangeOrientationCodes(): void
    {
        self::assertNull(Orientation::fromExifValue(9));
    }

    /**
     * Uses reserved SubjectDistanceRange codes that should not be mapped.
     * Confirms the mapping returns null for reserved values.
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function returnsNullForReservedCompositeImageCodes(): void
    {
        self::assertNull(CompositeImage::fromExifValue(4));
        self::assertNull(CompositeImage::fromExifValue('7'));
    }

    /**
     * Supplies photometric codes that are reserved or invalid.
     * Verifies the mapping returns null for these unsupported values.
     *
     * @return void
     */
    #[Test]
    public function returnsNullForReservedPhotometricCodes(): void
    {
        self::assertNull(Photometric::fromExifValue(0));
        self::assertNull(Photometric::fromExifValue(3));
        self::assertNull(Photometric::fromExifValue(8));
    }

    /**
     * Maps GPS reference and status codes expressed as strings to enum values.
     * Ensures each EXIF Table 27 code resolves to the correct enum.
     *
     * @return void
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
     * Provides invalid GPS reference/status codes.
     * Confirms the mapping returns null for unsupported GPS string values.
     *
     * @return void
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
     * Checks rotationDescription strings for all orientation enum values.
     * Ensures each description matches the expected rotation/mirroring semantics.
     *
     * @return void
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
     * Verifies rotationDegrees for each orientation enum value.
     * Confirms the degrees align with the expected rotation direction.
     *
     * @return void
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
     * Evaluates isMirrored across all orientation enum values.
     * Ensures the mirror flag matches the expected orientation semantics.
     *
     * @return void
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
