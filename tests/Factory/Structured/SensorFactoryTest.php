<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Core\Util\MatrixValidator;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reader\IsoSensitivityReader;
use MagicSunday\ImageMeta\Exif\Reader\SensorDataReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\SensorFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function count;
use function strlen;

/**
 * Exercises SensorFactory for mapping sensor-related EXIF tags into Sensor values.
 * It verifies CFA pattern decoding, spectral sensitivity, and focal plane resolutions.
 * The suite covers resolution unit conversions and optional sensor fields.
 * This ensures sensor metadata is normalized consistently for structured output.
 *
 * @internal
 */
#[CoversClass(SensorFactory::class)]
#[UsesClass(MatrixValidator::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(FocalReader::class)]
#[UsesClass(IsoSensitivityReader::class)]
#[UsesClass(SensorDataReader::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(CfaPattern::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(SpatialFrequencyResponse::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class SensorFactoryTest extends TestCase
{
    /**
     * Supplies EXIF sensor tags including CFA pattern and focal plane resolutions.
     * Verifies SensorFactory builds a CfaPattern and maps sensor-related fields correctly.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $sensor = $this->createSensor($this->parsedExif(
            cfaPattern: [2, 2, 0, 1, 1, 2],
            spectralSensitivity: 'ISO 12232',
            focalPlaneXResolution: 3000.0,
            focalPlaneYResolution: 3000.0,
            focalPlaneResolutionUnit: ResolutionUnit::Inches,
        ));

        self::assertInstanceOf(CfaPattern::class, $sensor->cfaPattern);
        self::assertSame([
            [CfaPatternColor::Red, CfaPatternColor::Green],
            [CfaPatternColor::Green, CfaPatternColor::Blue],
        ], $sensor->cfaPattern->grid());
        self::assertSame('ISO 12232', $sensor->spectralSensitivity);
        self::assertSame(3000.0, $sensor->focalPlaneXResolution);
        self::assertSame(3000.0, $sensor->focalPlaneYResolution);
        self::assertSame(ResolutionUnit::Inches, $sensor->focalPlaneResolutionUnit);
        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->sensorType);
        self::assertNull($sensor->ibis);
    }

    /**
     * Creates Metadata without an EXIF document.
     * Ensures the sensor value object contains null fields including ibis.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $sensor = $this->createSensor();

        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->sensorType);
        self::assertNull($sensor->ibis);
        self::assertNull($sensor->cfaPattern);
        self::assertNull($sensor->spectralSensitivity);
        self::assertNull($sensor->oecf);
        self::assertNull($sensor->spatialFrequencyResponse);
        self::assertNull($sensor->focalPlaneXResolution);
        self::assertNull($sensor->focalPlaneYResolution);
        self::assertNull($sensor->focalPlaneResolutionUnit);
    }

    /**
     * Supplies an invalid focal plane resolution unit code alongside valid resolutions.
     * Confirms the unit is rejected while numeric resolution values are retained.
     */
    #[Test]
    public function handlesInvalidResolutionUnit(): void
    {
        $sensor = $this->createSensor($this->parsedExif(
            cfaPattern: [],
            spectralSensitivity: null,
            focalPlaneXResolution: 2000.0,
            focalPlaneYResolution: 2000.0,
            focalPlaneResolutionUnit: null,
            focalPlaneResolutionUnitCode: 999,
        ));

        self::assertNull($sensor->focalPlaneResolutionUnit);
        self::assertSame(2000.0, $sensor->focalPlaneXResolution);
        self::assertSame(2000.0, $sensor->focalPlaneYResolution);
    }

    /**
     * Provides a valid focal plane resolution unit of centimeters.
     * Ensures the factory maps the unit code to the correct enum.
     */
    #[Test]
    public function convertsResolutionUnitCmToEnum(): void
    {
        $sensor = $this->createSensor($this->parsedExif(
            cfaPattern: [],
            spectralSensitivity: null,
            focalPlaneXResolution: 1200.0,
            focalPlaneYResolution: 1200.0,
            focalPlaneResolutionUnit: ResolutionUnit::Centimeter,
        ));

        self::assertSame(ResolutionUnit::Centimeter, $sensor->focalPlaneResolutionUnit);
    }

    /**
     * Supplies an IFD entry with wrong TIFF type for spectral sensitivity (SHORT instead of ASCII).
     * Verifies the factory degrades gracefully to null for the mistyped field.
     */
    #[Test]
    public function returnsNullSpectralSensitivityWhenTagHasWrongType(): void
    {
        $sensor = $this->createSensor($this->parsedExifFromEntries([
            ExifTag::SPECTRAL_SENSITIVITY => new IfdEntry(
                ExifTag::SPECTRAL_SENSITIVITY,
                3,
                1,
                42,
            ),
        ]));

        self::assertNull($sensor->spectralSensitivity);
    }

    /**
     * Supplies a CFA pattern with only a dimension header but no color data.
     * Verifies the factory handles the truncated pattern without crashing.
     */
    #[Test]
    public function handlesTruncatedCfaPattern(): void
    {
        // CFA pattern needs at least [cols, rows, ...colors] — provide only dimensions
        $this->createSensor($this->parsedExifFromEntries([
            ExifTag::CFA_PATTERN => new IfdEntry(
                ExifTag::CFA_PATTERN,
                7,
                2,
                [2, 2],
            ),
        ]));

        // Truncated CFA pattern should either be null or have incomplete data
        // The key is that no exception is thrown
        $this->addToAssertionCount(1);
    }

    private function createSensor(?ParsedExif $exifDoc = null): Sensor
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
        );

        return new SensorFactory()->create($metadata);
    }

    /**
     * @param list<int> $cfaPattern
     */
    private function parsedExif(
        array $cfaPattern,
        ?string $spectralSensitivity,
        ?float $focalPlaneXResolution,
        ?float $focalPlaneYResolution,
        ?ResolutionUnit $focalPlaneResolutionUnit,
        ?int $focalPlaneResolutionUnitCode = null,
    ): ParsedExif {
        $exifEntries = [];

        if ($cfaPattern !== []) {
            $exifEntries[ExifTag::CFA_PATTERN] = new IfdEntry(
                ExifTag::CFA_PATTERN,
                7,
                count($cfaPattern),
                $cfaPattern,
            );
        }

        if ($spectralSensitivity !== null) {
            $exifEntries[ExifTag::SPECTRAL_SENSITIVITY] = new IfdEntry(
                ExifTag::SPECTRAL_SENSITIVITY,
                2,
                strlen($spectralSensitivity),
                $spectralSensitivity,
            );
        }

        if ($focalPlaneXResolution !== null) {
            $exifEntries[ExifTag::FOCAL_PLANE_X_RESOLUTION] = new IfdEntry(
                ExifTag::FOCAL_PLANE_X_RESOLUTION,
                5,
                1,
                $focalPlaneXResolution,
            );
        }

        if ($focalPlaneYResolution !== null) {
            $exifEntries[ExifTag::FOCAL_PLANE_Y_RESOLUTION] = new IfdEntry(
                ExifTag::FOCAL_PLANE_Y_RESOLUTION,
                5,
                1,
                $focalPlaneYResolution,
            );
        }

        if ($focalPlaneResolutionUnit instanceof ResolutionUnit) {
            $exifEntries[ExifTag::FOCAL_PLANE_RESOLUTION_UNIT] = new IfdEntry(
                ExifTag::FOCAL_PLANE_RESOLUTION_UNIT,
                3,
                1,
                $focalPlaneResolutionUnit->value,
            );
        } elseif ($focalPlaneResolutionUnitCode !== null) {
            $exifEntries[ExifTag::FOCAL_PLANE_RESOLUTION_UNIT] = new IfdEntry(
                ExifTag::FOCAL_PLANE_RESOLUTION_UNIT,
                3,
                1,
                $focalPlaneResolutionUnitCode,
            );
        }

        return $this->parsedExifFromEntries($exifEntries);
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromEntries(array $exifEntries): ParsedExif
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
