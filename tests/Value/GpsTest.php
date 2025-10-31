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
    public function exposesAliasGetters(): void
    {
        $gps = new Gps(
            latitude: 1.23,
            latitudeRef: 'N',
            longitude: 4.56,
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

        self::assertSame('N', $gps->latitudeReference());
        self::assertSame($gps->latitudeRef, $gps->latitudeReference());
        self::assertSame('E', $gps->longitudeReference());
        self::assertSame($gps->longitudeRef, $gps->longitudeReference());
        self::assertSame(0.8, $gps->dilutionOfPrecision());
        self::assertSame($gps->dop, $gps->dilutionOfPrecision());
        self::assertSame('K', $gps->speedReference());
        self::assertSame($gps->speedRef, $gps->speedReference());
        self::assertSame('M', $gps->speedOriginalReference());
        self::assertSame($gps->speedOriginalRef, $gps->speedOriginalReference());
        self::assertSame('T', $gps->trackReference());
        self::assertSame($gps->trackRef, $gps->trackReference());
        self::assertSame('M', $gps->imageDirectionReference());
        self::assertSame($gps->imageDirectionRef, $gps->imageDirectionReference());
        self::assertSame('N', $gps->destinationLatitudeReference());
        self::assertSame($gps->destinationLatitudeRef, $gps->destinationLatitudeReference());
        self::assertSame('E', $gps->destinationLongitudeReference());
        self::assertSame($gps->destinationLongitudeRef, $gps->destinationLongitudeReference());
        self::assertSame('T', $gps->destinationBearingReference());
        self::assertSame($gps->destinationBearingRef, $gps->destinationBearingReference());
        self::assertSame('K', $gps->destinationDistanceReference());
        self::assertSame($gps->destinationDistanceRef, $gps->destinationDistanceReference());
        self::assertSame('N', $gps->destinationDistanceOriginalReference());
        self::assertSame($gps->destinationDistanceOriginalRef, $gps->destinationDistanceOriginalReference());

        $methods = get_class_methods($gps);

        self::assertContains('latitudeRef', $methods);
        self::assertContains('longitudeRef', $methods);
        self::assertContains('dop', $methods);
        self::assertContains('speedRef', $methods);
        self::assertContains('speedOriginalRef', $methods);
        self::assertContains('trackRef', $methods);
        self::assertContains('imageDirectionRef', $methods);
        self::assertContains('destinationLatitudeRef', $methods);
        self::assertContains('destinationLongitudeRef', $methods);
        self::assertContains('destinationBearingRef', $methods);
        self::assertContains('destinationDistanceRef', $methods);
        self::assertContains('destinationDistanceOriginalRef', $methods);
    }

    #[Test]
    public function calculatesSignedCoordinates(): void
    {
        $gps = new Gps(
            latitude: 12.5,
            latitudeRef: 's',
            longitude: 7.5,
            longitudeRef: 'W',
        );

        self::assertSame(-12.5, $gps->latitudeSigned());
        self::assertSame(-7.5, $gps->longitudeSigned());

        $latitudeCoordinate  = $gps->latitudeCoordinate();
        $longitudeCoordinate = $gps->longitudeCoordinate();

        self::assertNotNull($latitudeCoordinate);
        self::assertNotNull($longitudeCoordinate);
        self::assertSame(-12.5, $latitudeCoordinate->signed());
        self::assertSame('S', $latitudeCoordinate->reference());
        self::assertSame('12.5° S', (string) $latitudeCoordinate);
        self::assertSame(-7.5, $longitudeCoordinate->signed());
        self::assertSame('W', $longitudeCoordinate->reference());
        self::assertSame('7.5° W', (string) $longitudeCoordinate);
    }

    #[Test]
    public function returnsUtcTimestamp(): void
    {
        $timestamp = new DateTimeImmutable('2024-05-17 12:34:56', new DateTimeZone('Europe/Berlin'));
        $gps       = new Gps(timestamp: $timestamp);

        $utcTimestamp = $gps->timestamp();

        self::assertNotNull($utcTimestamp);
        self::assertSame('UTC', $utcTimestamp->getTimezone()->getName());
        self::assertSame(
            $timestamp->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            $utcTimestamp->getTimestamp(),
        );
    }
}
