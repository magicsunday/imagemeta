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
use MagicSunday\ImageMeta\Value\Gps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gps::class)]
final class GpsTest extends TestCase
{
    #[Test]
    public function exposesGpsProperties(): void
    {
        $gps = new Gps(
            latitude: 1.23,
            longitude: 4.56,
            latitudeRef: 'N',
            longitudeRef: 'E',
            dop: 0.8,
            speedRef: 'K',
            speedOriginalRef: 'M',
            trackRef: 'T',
            imageDirectionRef: 'M',
            destinationLatitudeRef: 'N',
            destinationLongitudeRef: 'E',
            destinationBearingRef: 'T',
            destinationDistanceRef: 'K',
            destinationDistanceOriginalRef: 'N',
        );

        self::assertSame(1.23, $gps->latitude);
        self::assertSame('N', $gps->latitudeRef);
        self::assertSame(4.56, $gps->longitude);
        self::assertSame('E', $gps->longitudeRef);
        self::assertSame(0.8, $gps->dop);
        self::assertSame('K', $gps->speedRef);
        self::assertSame('M', $gps->speedOriginalRef);
        self::assertSame('T', $gps->trackRef);
        self::assertSame('M', $gps->imageDirectionRef);
        self::assertSame('N', $gps->destinationLatitudeRef);
        self::assertSame('E', $gps->destinationLongitudeRef);
        self::assertSame('T', $gps->destinationBearingRef);
        self::assertSame('K', $gps->destinationDistanceRef);
        self::assertSame('N', $gps->destinationDistanceOriginalRef);
    }

    #[Test]
    public function calculatesSignedCoordinates(): void
    {
        $gps = new Gps(
            latitude: 12.5,
            longitude: 7.5,
            latitudeRef: 's',
            longitudeRef: 'W',
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
