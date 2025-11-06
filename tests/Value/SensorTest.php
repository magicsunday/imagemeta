<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Sensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Sensor value object.
 */
#[CoversClass(Sensor::class)]
final class SensorTest extends TestCase
{
    #[Test]
    public function constructsWithBasicSensorInfo(): void
    {
        $sensor = new Sensor(
            pixelPitchUm: 3.76,
            cfaWidth: 2,
            cfaHeight: 2,
            sensorType: 'CMOS',
            ibis: true,
            cfaPattern: null,
            spectralSensitivity: null,
            oecf: null,
            spatialFrequencyResponse: null,
            focalPlaneXResolution: null,
            focalPlaneYResolution: null,
            focalPlaneResolutionUnit: null,
        );

        self::assertSame(3.76, $sensor->pixelPitchUm);
        self::assertSame(2, $sensor->cfaWidth);
        self::assertSame(2, $sensor->cfaHeight);
        self::assertSame('CMOS', $sensor->sensorType);
        self::assertTrue($sensor->ibis);
    }

    #[Test]
    public function constructsWithCFAPattern(): void
    {
        $cfaPattern = [0, 1, 1, 2]; // Bayer RGGB pattern

        $sensor = new Sensor(
            pixelPitchUm: null,
            cfaWidth: 2,
            cfaHeight: 2,
            sensorType: null,
            ibis: false,
            cfaPattern: $cfaPattern,
            spectralSensitivity: null,
            oecf: null,
            spatialFrequencyResponse: null,
            focalPlaneXResolution: null,
            focalPlaneYResolution: null,
            focalPlaneResolutionUnit: null,
        );

        self::assertSame($cfaPattern, $sensor->cfaPattern);
    }

    #[Test]
    public function constructsWithFocalPlaneResolution(): void
    {
        $sensor = new Sensor(
            pixelPitchUm: null,
            cfaWidth: null,
            cfaHeight: null,
            sensorType: null,
            ibis: false,
            cfaPattern: null,
            spectralSensitivity: null,
            oecf: null,
            spatialFrequencyResponse: null,
            focalPlaneXResolution: 3000.0,
            focalPlaneYResolution: 3000.0,
            focalPlaneResolutionUnit: ResolutionUnit::CENTIMETER,
        );

        self::assertSame(3000.0, $sensor->focalPlaneXResolution);
        self::assertSame(3000.0, $sensor->focalPlaneYResolution);
        self::assertSame(ResolutionUnit::CENTIMETER, $sensor->focalPlaneResolutionUnit);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $sensor = new Sensor(
            pixelPitchUm: null,
            cfaWidth: null,
            cfaHeight: null,
            sensorType: null,
            ibis: false,
            cfaPattern: null,
            spectralSensitivity: null,
            oecf: null,
            spatialFrequencyResponse: null,
            focalPlaneXResolution: null,
            focalPlaneYResolution: null,
            focalPlaneResolutionUnit: null,
        );

        self::assertNull($sensor->pixelPitchUm);
        self::assertNull($sensor->cfaWidth);
        self::assertNull($sensor->sensorType);
        self::assertFalse($sensor->ibis);
        self::assertNull($sensor->cfaPattern);
        self::assertNull($sensor->focalPlaneXResolution);
    }
}
