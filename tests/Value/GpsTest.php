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
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\GpsPosition;
use MagicSunday\ImageMeta\Value\GpsTiming;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Gps value object for signed coordinate calculation and UTC normalization.
 *
 * @internal
 */
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(GpsPosition::class)]
#[UsesClass(GpsTiming::class)]
#[CoversClass(Gps::class)]
final class GpsTest extends TestCase
{
    /**
     * Calculates signed coordinates and derived coordinate wrappers from South/West refs.
     */
    #[Test]
    public function calculatesSignedCoordinates(): void
    {
        $position            = new GpsPosition(
            latitude: 12.5,
            longitude: 7.5,
            latitudeRef: GpsLatLonRef::South,
            longitudeRef: GpsLatLonRef::West,
        );

        $gps                 = new Gps(
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
        $timestamp    = new DateTimeImmutable('2024-05-17 12:34:56', new DateTimeZone('Europe/Berlin'));

        $timing       = new GpsTiming(timestamp: $timestamp);
        $gps          = new Gps(timing: $timing);

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
