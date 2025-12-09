<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value\Enum;

use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
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
}
