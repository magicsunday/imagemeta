<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SensorFactory;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function strlen;

#[CoversClass(SensorFactory::class)]
final class SensorFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            cfaPattern: [0, 1, 1, 2],
            spectralSensitivity: 'ISO 12232',
            focalPlaneXResolution: 3000.0,
            focalPlaneYResolution: 3000.0,
            focalPlaneResolutionUnit: ResolutionUnit::INCHES,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertSame([0, 1, 1, 2], $sensor->cfaPattern);
        self::assertSame('ISO 12232', $sensor->spectralSensitivity);
        self::assertSame(3000.0, $sensor->focalPlaneXResolution);
        self::assertSame(3000.0, $sensor->focalPlaneYResolution);
        self::assertSame(ResolutionUnit::INCHES, $sensor->focalPlaneResolutionUnit);
        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->sensorType);
        self::assertFalse($sensor->ibis);
    }

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

    #[Test]
    public function convertsResolutionUnitCmToEnum(): void
    {
        $parsedExif = $this->parsedExif(
            cfaPattern: [],
            spectralSensitivity: null,
            focalPlaneXResolution: 1200.0,
            focalPlaneYResolution: 1200.0,
            focalPlaneResolutionUnit: ResolutionUnit::CENTIMETER,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertSame(ResolutionUnit::CENTIMETER, $sensor->focalPlaneResolutionUnit);
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
