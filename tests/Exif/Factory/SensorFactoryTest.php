<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\SensorFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
final class SensorFactoryTest extends TestCase
{
    /**
     * Supplies EXIF sensor tags including CFA pattern and focal plane resolutions.
     * Verifies SensorFactory builds a CfaPattern and maps sensor-related fields correctly.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            cfaPattern: [2, 2, 0, 1, 1, 2],
            spectralSensitivity: 'ISO 12232',
            focalPlaneXResolution: 3000.0,
            focalPlaneYResolution: 3000.0,
            focalPlaneResolutionUnit: ResolutionUnit::Inches,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

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
        self::assertFalse($sensor->ibis);
    }

    /**
     * Creates Metadata without an EXIF document.
     * Ensures the sensor value object contains null fields and disables ibis.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->sensorType);
        self::assertFalse($sensor->ibis);
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
        $parsedExif = $this->parsedExif(
            cfaPattern: [],
            spectralSensitivity: null,
            focalPlaneXResolution: 2000.0,
            focalPlaneYResolution: 2000.0,
            focalPlaneResolutionUnit: null,
            focalPlaneResolutionUnitCode: 999,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

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
        $parsedExif = $this->parsedExif(
            cfaPattern: [],
            spectralSensitivity: null,
            focalPlaneXResolution: 1200.0,
            focalPlaneYResolution: 1200.0,
            focalPlaneResolutionUnit: ResolutionUnit::Centimeter,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertSame(ResolutionUnit::Centimeter, $sensor->focalPlaneResolutionUnit);
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
