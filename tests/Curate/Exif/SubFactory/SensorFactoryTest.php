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
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Sensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensorFactory::class)]
final class SensorFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('cfaPattern')->willReturn([0, 1, 1, 2]);
        $exifDoc->method('spectralSensitivity')->willReturn('ISO 12232');
        $exifDoc->method('oecf')->willReturn([1, 2, 3]);
        $exifDoc->method('spatialFrequencyResponse')->willReturn([0.5, 1.0, 1.5]);
        $exifDoc->method('focalPlaneXResolution')->willReturn(3000.0);
        $exifDoc->method('focalPlaneYResolution')->willReturn(3000.0);
        $exifDoc->method('focalPlaneResolutionUnit')->willReturn(2);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertInstanceOf(Sensor::class, $sensor);
        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->sensorType);
        self::assertFalse($sensor->ibis);
        self::assertSame([0, 1, 1, 2], $sensor->cfaPattern);
        self::assertSame('ISO 12232', $sensor->spectralSensitivity);
        self::assertSame([1, 2, 3], $sensor->oecf);
        self::assertSame([0.5, 1.0, 1.5], $sensor->spatialFrequencyResponse);
        self::assertSame(3000.0, $sensor->focalPlaneXResolution);
        self::assertSame(3000.0, $sensor->focalPlaneYResolution);
        self::assertSame(ResolutionUnit::INCH, $sensor->focalPlaneResolutionUnit);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertInstanceOf(Sensor::class, $sensor);
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
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('cfaPattern')->willReturn(null);
        $exifDoc->method('spectralSensitivity')->willReturn(null);
        $exifDoc->method('oecf')->willReturn(null);
        $exifDoc->method('spatialFrequencyResponse')->willReturn(null);
        $exifDoc->method('focalPlaneXResolution')->willReturn(2000.0);
        $exifDoc->method('focalPlaneYResolution')->willReturn(2000.0);
        $exifDoc->method('focalPlaneResolutionUnit')->willReturn(999);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertInstanceOf(Sensor::class, $sensor);
        self::assertNull($sensor->focalPlaneResolutionUnit);
    }

    #[Test]
    public function convertsResolutionUnitCmToEnum(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('cfaPattern')->willReturn(null);
        $exifDoc->method('spectralSensitivity')->willReturn(null);
        $exifDoc->method('oecf')->willReturn(null);
        $exifDoc->method('spatialFrequencyResponse')->willReturn(null);
        $exifDoc->method('focalPlaneXResolution')->willReturn(1200.0);
        $exifDoc->method('focalPlaneYResolution')->willReturn(1200.0);
        $exifDoc->method('focalPlaneResolutionUnit')->willReturn(3);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new SensorFactory();
        $sensor  = $factory->create($metadata);

        self::assertInstanceOf(Sensor::class, $sensor);
        self::assertSame(ResolutionUnit::CM, $sensor->focalPlaneResolutionUnit);
    }
}
