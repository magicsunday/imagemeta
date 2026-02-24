<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\GpsDestination;
use MagicSunday\ImageMeta\Value\GpsMeasurement;
use MagicSunday\ImageMeta\Value\GpsMovement;
use MagicSunday\ImageMeta\Value\GpsPosition;
use MagicSunday\ImageMeta\Value\GpsTiming;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Gps value object for coordinates, references, and timestamps.
 * It verifies lat/lon values, hemisphere enums, and altitude/distance fields.
 * The suite covers speed, direction, and time zone conversion logic.
 * This ensures GPS metadata is normalized and represented consistently.
 *
 * @internal
 */
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(GpsDestination::class)]
#[UsesClass(GpsMeasurement::class)]
#[UsesClass(GpsMovement::class)]
#[UsesClass(GpsPosition::class)]
#[UsesClass(GpsTiming::class)]
#[CoversClass(Gps::class)]
final class GpsTest extends TestCase
{
    /**
     * Exposes GPS fields and enum references from the constructor.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesGpsProperties(): void
    {
        $position = new GpsPosition(
            latitude: 1.23,
            longitude: 4.56,
            latitudeRef: GpsLatLonRef::North,
            longitudeRef: GpsLatLonRef::East,
        );

        $destination = new GpsDestination(
            latitudeRef: GpsLatLonRef::North,
            longitudeRef: GpsLatLonRef::East,
            bearingRef: GpsDirectionRef::TrueDirection,
            distanceRef: GpsDistanceRef::Kilometers,
            distanceOriginalRef: GpsDistanceRef::NauticalMiles,
        );

        $movement = new GpsMovement(
            speedRef: GpsSpeedRef::KilometersPerHour,
            speedOriginalRef: GpsSpeedRef::MilesPerHour,
            trackRef: GpsDirectionRef::TrueDirection,
            imageDirectionRef: GpsDirectionRef::MagneticDirection,
        );

        $measurement = new GpsMeasurement(
            dop: 0.8,
        );

        $gps = new Gps(
            position: $position,
            destination: $destination,
            movement: $movement,
            measurement: $measurement,
        );

        self::assertNotNull($gps->position);
        self::assertNotNull($gps->measurement);
        self::assertNotNull($gps->movement);
        self::assertNotNull($gps->destination);

        self::assertSame(1.23, $gps->position->latitude);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertSame(4.56, $gps->position->longitude);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
        self::assertSame(0.8, $gps->measurement->dop);
        self::assertSame(GpsSpeedRef::KilometersPerHour, $gps->movement->speedRef);
        self::assertSame(GpsSpeedRef::MilesPerHour, $gps->movement->speedOriginalRef);
        self::assertSame(GpsDirectionRef::TrueDirection, $gps->movement->trackRef);
        self::assertSame(GpsDirectionRef::MagneticDirection, $gps->movement->imageDirectionRef);
        self::assertSame(GpsLatLonRef::North, $gps->destination->latitudeRef);
        self::assertSame(GpsLatLonRef::East, $gps->destination->longitudeRef);
        self::assertSame(GpsDirectionRef::TrueDirection, $gps->destination->bearingRef);
        self::assertSame(GpsDistanceRef::Kilometers, $gps->destination->distanceRef);
        self::assertSame(GpsDistanceRef::NauticalMiles, $gps->destination->distanceOriginalRef);
    }

    /**
     * Calculates signed coordinates and derived coordinate wrappers.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function calculatesSignedCoordinates(): void
    {
        $position = new GpsPosition(
            latitude: 12.5,
            longitude: 7.5,
            latitudeRef: GpsLatLonRef::South,
            longitudeRef: GpsLatLonRef::West,
        );

        $gps = new Gps(
            position: $position,
        );

        self::assertNotNull($gps->position);

        self::assertSame(-12.5, $gps->position->latitudeSigned);
        self::assertSame(-7.5, $gps->position->longitudeSigned);

        $latitudeCoordinate  = $gps->position->latitudeCoordinate;
        $longitudeCoordinate = $gps->position->longitudeCoordinate;

        self::assertNotNull($latitudeCoordinate);
        self::assertNotNull($longitudeCoordinate);
        self::assertSame(-12.5, $latitudeCoordinate->signed);
        self::assertSame('S', $latitudeCoordinate->reference);
        self::assertSame('12.5° S', (string) $latitudeCoordinate);
        self::assertSame(-7.5, $longitudeCoordinate->signed);
        self::assertSame('W', $longitudeCoordinate->reference);
        self::assertSame('7.5° W', (string) $longitudeCoordinate);
    }

    /**
     * Normalizes timestamps to UTC.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function returnsUtcTimestamp(): void
    {
        $timestamp = new DateTimeImmutable('2024-05-17 12:34:56', new DateTimeZone('Europe/Berlin'));

        $timing = new GpsTiming(timestamp: $timestamp);
        $gps    = new Gps(timing: $timing);

        self::assertNotNull($gps->timing);

        $utcTimestamp = $gps->timing->timestamp;

        self::assertNotNull($utcTimestamp);
        self::assertSame('UTC', $utcTimestamp->getTimezone()->getName());
        self::assertSame(
            $timestamp->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            $utcTimestamp->getTimestamp(),
        );
    }
}
