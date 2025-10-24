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
use MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Support\GpsTiffBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver
 */
#[CoversClass(ExifTagResolver::class)]
#[UsesClass(ExifDocument::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(GpsTiffBuilder::class)]
final class ExifTagResolverTest extends TestCase
{
    /**
     * Ensures the resolver exposes the extended GPS metadata decoded from the GPS IFD.
     */
    #[Test]
    public function exposesExtendedGpsMetadata(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID        => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF      => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE          => new IfdEntry(
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
            ExifTag::GPS_ALTITUDE_REF        => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE            => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(150, 1)),
            ExifTag::GPS_TIME_STAMP          => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(56789, 1000),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP          => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:06'),
            ExifTag::GPS_SATELLITES          => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '05'),
            ExifTag::GPS_STATUS              => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'A'),
            ExifTag::GPS_MEASURE_MODE        => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '3'),
            ExifTag::GPS_DOP                 => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(25, 10)),
            ExifTag::GPS_SPEED_REF           => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'K'),
            ExifTag::GPS_SPEED               => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_TRACK_REF           => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'T'),
            ExifTag::GPS_TRACK               => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(12345, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF   => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'M'),
            ExifTag::GPS_IMG_DIRECTION       => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(2500, 10)),
            ExifTag::GPS_MAP_DATUM           => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 6, 'WGS-84'),
            ExifTag::GPS_DEST_LATITUDE_REF   => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE       => new IfdEntry(
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
            ExifTag::GPS_DEST_BEARING_REF   => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING       => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(123, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF  => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'K'),
            ExifTag::GPS_DEST_DISTANCE      => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
            ExifTag::GPS_PROCESSING_METHOD  => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0NETWORK"),
            ExifTag::GPS_AREA_INFORMATION   => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0AreaName"),
            ExifTag::GPS_DIFFERENTIAL       => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $document = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);
        $resolver = new ExifTagResolver($document);

        $gps = $resolver->gps();
        self::assertSame('N', $gps['lat_ref']);
        self::assertEqualsWithDelta(51.5, $gps['lat'], 0.000001);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta(8.5, $gps['lon'], 0.000001);
        self::assertSame(0, $gps['alt_ref']);
        self::assertEqualsWithDelta(150.0, $gps['alt'], 0.000001);

        self::assertSame('N', $resolver->gpsLatitudeRef());
        self::assertSame('E', $resolver->gpsLongitudeRef());
        self::assertSame(0, $resolver->gpsAltitudeRef());
        self::assertSame('3.0.0.0', $resolver->gpsVersion());
        self::assertSame('05', $resolver->gpsSatellites());
        self::assertSame('A', $resolver->gpsStatus());
        self::assertSame('3', $resolver->gpsMeasureMode());
        self::assertEqualsWithDelta(2.5, $resolver->gpsDop(), 0.000001);
        self::assertSame('K', $resolver->gpsSpeedRef());
        self::assertEqualsWithDelta(20.0, $resolver->gpsSpeed(), 0.000001);
        self::assertSame('T', $resolver->gpsTrackRef());
        self::assertEqualsWithDelta(123.45, $resolver->gpsTrack(), 0.000001);
        self::assertSame('M', $resolver->gpsImgDirectionRef());
        self::assertEqualsWithDelta(250.0, $resolver->gpsImgDirection(), 0.000001);
        self::assertSame('WGS-84', $resolver->gpsMapDatum());
        self::assertSame('N', $resolver->gpsDestinationLatitudeRef());
        self::assertEqualsWithDelta(41.0, $resolver->gpsDestinationLatitude(), 0.000001);
        self::assertSame('E', $resolver->gpsDestinationLongitudeRef());
        self::assertEqualsWithDelta(8.5, $resolver->gpsDestinationLongitude(), 0.000001);
        self::assertSame('T', $resolver->gpsDestinationBearingRef());
        self::assertEqualsWithDelta(123.0, $resolver->gpsDestinationBearing(), 0.000001);
        self::assertSame('K', $resolver->gpsDestinationDistanceRef());
        self::assertEqualsWithDelta(42000.0, $resolver->gpsDestinationDistance(), 0.000001);
        self::assertSame('NETWORK', $resolver->gpsProcessingMethod());
        self::assertSame('AreaName', $resolver->gpsAreaInformation());
        self::assertSame('2024-05-06', $resolver->gpsDate());
        self::assertSame('12:34:56.789', $resolver->gpsTime());

        $timestamp = $resolver->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));
        self::assertSame('12:34:56.789000', $timestamp->format('H:i:s.u'));

        self::assertSame(2, $resolver->gpsDifferential());
        self::assertEqualsWithDelta(1.5, $resolver->gpsHorizontalPositioningError(), 0.000001);
    }

    /**
     * Ensures GPS data parsed from a TIFF blob is normalised and exposed via resolver helpers.
     */
    #[Test]
    public function resolvesGpsMetadataFromSyntheticTiff(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(GpsTiffBuilder::buildClassicGpsTiff());
        $resolver = new ExifTagResolver($document);

        $gps = $resolver->gps();

        self::assertSame('N', $gps['lat_ref']);
        self::assertEqualsWithDelta(51.5, $gps['lat'], 0.000001);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta(8.5, $gps['lon'], 0.000001);
        self::assertSame(0, $gps['alt_ref']);
        self::assertEqualsWithDelta(150.0, $gps['alt'], 0.000001);
        self::assertEqualsWithDelta(90.0, $gps['track'], 0.000001);
        self::assertEqualsWithDelta(45.0, $gps['img_direction'], 0.000001);
        self::assertEqualsWithDelta(45.0, $gps['dest_bearing'], 0.000001);
        self::assertEqualsWithDelta(42000.0, $gps['dest_distance_m'], 0.000001);

        self::assertSame('K', $resolver->gpsSpeedRef());
        self::assertEqualsWithDelta(20.0, $resolver->gpsSpeed(), 0.000001);
        self::assertSame('T', $resolver->gpsTrackRef());
        self::assertEqualsWithDelta(90.0, $resolver->gpsTrack(), 0.000001);
        self::assertSame('M', $resolver->gpsImgDirectionRef());
        self::assertEqualsWithDelta(45.0, $resolver->gpsImgDirection(), 0.000001);
        self::assertSame('T', $resolver->gpsDestinationBearingRef());
        self::assertEqualsWithDelta(45.0, $resolver->gpsDestinationBearing(), 0.000001);
        self::assertSame('K', $resolver->gpsDestinationDistanceRef());
        self::assertEqualsWithDelta(42000.0, $resolver->gpsDestinationDistance(), 0.000001);
        self::assertSame('2024-05-06', $resolver->gpsDate());
        self::assertSame('12:34:56.789', $resolver->gpsTime());

        $timestamp = $resolver->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(2, $resolver->gpsDifferential());
        self::assertEqualsWithDelta(1.5, $resolver->gpsHorizontalPositioningError(), 0.000001);
    }

    /**
     * Ensures temporal helper methods expose fractional seconds and offsets.
     */
    #[Test]
    public function exposesTemporalMetadata(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME           => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '987'),
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '456'),
            ExifTag::OFFSET_TIME            => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+00:30'),
            ExifTag::OFFSET_TIME_ORIGINAL   => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '-01:30'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+01:45'),
            ExifTag::TIME_ZONE_OFFSET       => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 2, new ExifNumericList([-90, 120])),
            ExifTag::SELF_TIMER_MODE        => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 7),
            ExifTag::INTERLACE              => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
        ]);

        $document = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $resolver = new ExifTagResolver($document);

        self::assertSame('987', $resolver->subSecTime());
        self::assertSame('123', $resolver->subSecTimeOriginal());
        self::assertSame('456', $resolver->subSecTimeDigitized());
        self::assertSame('+00:30', $resolver->offsetTime());
        self::assertSame('-01:30', $resolver->offsetTimeOriginal());
        self::assertSame('+01:45', $resolver->offsetTimeDigitized());
        self::assertSame([-90, 120], $resolver->timeZoneOffsetMinutes());
        self::assertSame(7, $resolver->selfTimerModeSeconds());
        self::assertSame(1, $resolver->interlace());
    }
}

