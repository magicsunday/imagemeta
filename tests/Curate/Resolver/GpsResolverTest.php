<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Resolver;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Resolver\GpsResolver;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Gps;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\GpsResolver
 */
final class GpsResolverTest extends TestCase
{
    #[Test]
    public function resolvesCompleteGpsDatasetFromExif(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(51, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(150, 1),
            ),
            ExifTag::GPS_TIME_STAMP => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(56789, 1000),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:06'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '05'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'A'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '3'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(25, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'K'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'T'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(12345, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'M'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(2500, 10)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 6, 'WGS-84'),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(41, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(123, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'K'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0NETWORK"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0AreaName"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);

        $resolver = new GpsResolver();
        $gps      = $resolver->resolve($exifDocument, null);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertEqualsWithDelta(51.5, $gps->latitude, 1e-6);
        self::assertEqualsWithDelta(8.5, $gps->longitude, 1e-6);
        self::assertSame('N', $gps->latitudeRef);
        self::assertSame('E', $gps->longitudeRef);
        self::assertEqualsWithDelta(150.0, $gps->altitude, 1e-6);
        self::assertSame('05', $gps->satellites);
        self::assertSame('NETWORK', $gps->processingMethod);
        self::assertSame('AreaName', $gps->areaInformation);
        self::assertSame('2024-05-06', $gps->date);
        self::assertSame('12:34:56.789', $gps->time);
        self::assertInstanceOf(DateTimeImmutable::class, $gps->timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $gps->timestamp?->format(DATE_ATOM));
        self::assertEqualsWithDelta(42000.0, $gps->destinationDistanceMetres, 1e-6);
        self::assertEqualsWithDelta(1.5, $gps->horizontalPositioningError, 1e-6);
    }

    #[Test]
    public function fillsMissingFieldsFromXmp(): void
    {
        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/exif/1.0/}GPSLatitudeRef'  => ['S'],
            '{http://ns.adobe.com/exif/1.0/}GPSLatitude'     => ['51.5'],
            '{http://ns.adobe.com/exif/1.0/}GPSLongitudeRef' => ['W'],
            '{http://ns.adobe.com/exif/1.0/}GPSLongitude'    => ['8.5'],
            '{http://ns.adobe.com/exif/1.0/}GPSAltitudeRef'  => ['1'],
            '{http://ns.adobe.com/exif/1.0/}GPSAltitude'     => ['120.5'],
            '{http://ns.adobe.com/exif/1.0/}GPSSpeedRef'     => ['K'],
            '{http://ns.adobe.com/exif/1.0/}GPSSpeed'        => ['72'],
            '{http://ns.adobe.com/exif/1.0/}GPSDateStamp'    => ['2024-05-06'],
            '{http://ns.adobe.com/exif/1.0/}GPSTimeStamp'    => ['12:34:56'],
            '{http://ns.adobe.com/exif/1.0/}GPSDateTime'     => ['2024-05-06T12:34:56+02:00'],
        ]);

        $resolver = new GpsResolver();
        $gps      = $resolver->resolve(null, $xmpDocument);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertSame('S', $gps->latitudeRef);
        self::assertEqualsWithDelta(-51.5, $gps->latitude ?? 0.0, 1e-6);
        self::assertSame('W', $gps->longitudeRef);
        self::assertEqualsWithDelta(-8.5, $gps->longitude ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(-120.5, $gps->altitude ?? 0.0, 1e-6);
        self::assertSame('K', $gps->speedRef);
        self::assertEqualsWithDelta(20.0, $gps->speedMs ?? 0.0, 1e-6);
        self::assertSame('2024-05-06T10:34:56+00:00', $gps->timestamp?->format(DATE_ATOM));
    }
}
