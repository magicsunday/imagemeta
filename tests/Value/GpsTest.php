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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass(GpsCoordinate::class)]
#[CoversClass(Gps::class)]
final class GpsTest extends TestCase
{
    /**
     * Verifies that $gps->latitude equals 1.23.
     *
     * @return void
     */
    #[Test]
    public function exposesGpsProperties(): void
    {
        $gps = new Gps(
            latitude: 1.23,
            longitude: 4.56,
            latitudeRef: GpsLatLonRef::NORTH,
            longitudeRef: GpsLatLonRef::EAST,
            dop: 0.8,
            speedRef: GpsSpeedRef::KILOMETERS_PER_HOUR,
            speedOriginalRef: GpsSpeedRef::MILES_PER_HOUR,
            trackRef: GpsDirectionRef::TRUE_DIRECTION,
            imageDirectionRef: GpsDirectionRef::MAGNETIC_DIRECTION,
            destinationLatitudeRef: GpsLatLonRef::NORTH,
            destinationLongitudeRef: GpsLatLonRef::EAST,
            destinationBearingRef: GpsDirectionRef::TRUE_DIRECTION,
            destinationDistanceRef: GpsDistanceRef::KILOMETERS,
            destinationDistanceOriginalRef: GpsDistanceRef::NAUTICAL_MILES,
        );

        self::assertSame(1.23, $gps->latitude);
        self::assertSame(GpsLatLonRef::NORTH, $gps->latitudeRef);
        self::assertSame(4.56, $gps->longitude);
        self::assertSame(GpsLatLonRef::EAST, $gps->longitudeRef);
        self::assertSame(0.8, $gps->dop);
        self::assertSame(GpsSpeedRef::KILOMETERS_PER_HOUR, $gps->speedRef);
        self::assertSame(GpsSpeedRef::MILES_PER_HOUR, $gps->speedOriginalRef);
        self::assertSame(GpsDirectionRef::TRUE_DIRECTION, $gps->trackRef);
        self::assertSame(GpsDirectionRef::MAGNETIC_DIRECTION, $gps->imageDirectionRef);
        self::assertSame(GpsLatLonRef::NORTH, $gps->destinationLatitudeRef);
        self::assertSame(GpsLatLonRef::EAST, $gps->destinationLongitudeRef);
        self::assertSame(GpsDirectionRef::TRUE_DIRECTION, $gps->destinationBearingRef);
        self::assertSame(GpsDistanceRef::KILOMETERS, $gps->destinationDistanceRef);
        self::assertSame(GpsDistanceRef::NAUTICAL_MILES, $gps->destinationDistanceOriginalRef);
    }

    /**
     * Verifies that $gps->latitudeSigned equals -12.5.
     *
     * @return void
     */
    #[Test]
    public function calculatesSignedCoordinates(): void
    {
        $gps = new Gps(
            latitude: 12.5,
            longitude: 7.5,
            latitudeRef: GpsLatLonRef::SOUTH,
            longitudeRef: GpsLatLonRef::WEST,
        );

        self::assertSame(-12.5, $gps->latitudeSigned);
        self::assertSame(-7.5, $gps->longitudeSigned);

        $latitudeCoordinate  = $gps->latitudeCoordinate;
        $longitudeCoordinate = $gps->longitudeCoordinate;

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
     * Verifies that $utcTimestamp is not null.
     *
     * @return void
     */
    #[Test]
    public function returnsUtcTimestamp(): void
    {
        $timestamp = new DateTimeImmutable('2024-05-17 12:34:56', new DateTimeZone('Europe/Berlin'));
        $gps       = new Gps(timestamp: $timestamp);

        $utcTimestamp = $gps->timestamp;

        self::assertNotNull($utcTimestamp);
        self::assertSame('UTC', $utcTimestamp->getTimezone()->getName());
        self::assertSame(
            $timestamp->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            $utcTimestamp->getTimestamp(),
        );
    }
}
