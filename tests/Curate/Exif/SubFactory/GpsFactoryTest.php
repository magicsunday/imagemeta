<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\GpsFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Gps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GpsFactory::class)]
final class GpsFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $timestamp = new DateTimeImmutable('2023-06-15 14:30:00 UTC');

        $gpsData = [
            'lat_ref'                    => 'N',
            'lat'                        => 52.520008,
            'lon_ref'                    => 'E',
            'lon'                        => 13.404954,
            'alt_ref'                    => 0,
            'alt'                        => 35.0,
            'version'                    => '2.0.0.0',
            'version_raw'                => '2.0.0.0',
            'satellites'                 => '10',
            'status'                     => 'A',
            'measure_mode'               => '3',
            'dop'                        => 1.5,
            'speed_ref'                  => 'K',
            'speed_ms'                   => 5.0,
            'speed_original_ref'         => 'K',
            'speed_original'             => 18.0,
            'track_ref'                  => 'T',
            'track'                      => 90.0,
            'img_direction_ref'          => 'T',
            'img_direction'              => 90.0,
            'map_datum'                  => 'WGS-84',
            'dest_lat_ref'               => null,
            'dest_lat'                   => null,
            'dest_lon_ref'               => null,
            'dest_lon'                   => null,
            'dest_bearing_ref'           => null,
            'dest_bearing'               => null,
            'dest_distance_ref'          => null,
            'dest_distance_m'            => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
            'processing_method'          => 'GPS',
            'area_information'           => 'Berlin',
            'date'                       => '2023-06-15',
            'date_raw'                   => '2023:06:15',
            'time'                       => '14:30:00',
            'timestamp'                  => $timestamp,
            'differential'               => 0,
            'h_positioning_error'        => 5.0,
        ];

        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('gps')->willReturn($gpsData);
        $exifDoc->method('gpsTimestamp')->willReturn($timestamp);
        $exifDoc->method('gpsDateStamp')->willReturn(null);
        $exifDoc->method('gpsTimeStampString')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertSame(52.520008, $gps->latitude);
        self::assertSame(13.404954, $gps->longitude);
        self::assertSame('N', $gps->latitudeRef);
        self::assertSame('E', $gps->longitudeRef);
        self::assertSame(35.0, $gps->altitude);
        self::assertSame(0, $gps->altitudeRef);
        self::assertSame('2.0.0.0', $gps->version);
        self::assertSame('10', $gps->satellites);
        self::assertSame('A', $gps->status);
        self::assertSame('3', $gps->measureMode);
        self::assertSame(1.5, $gps->dop);
        self::assertSame('K', $gps->speedRef);
        self::assertSame(5.0, $gps->speedMs);
        self::assertSame('T', $gps->trackRef);
        self::assertSame(90.0, $gps->track);
        self::assertSame('WGS-84', $gps->mapDatum);
        self::assertSame('GPS', $gps->processingMethod);
        self::assertSame('Berlin', $gps->areaInformation);
        self::assertSame('2023-06-15', $gps->date);
        self::assertSame('14:30:00', $gps->time);
        self::assertInstanceOf(DateTimeImmutable::class, $gps->timestamp);
    }

    #[Test]
    public function createsEmptyGpsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertNull($gps->latitude);
        self::assertNull($gps->longitude);
    }

    #[Test]
    public function convertsSpeedToMetresPerSecond(): void
    {
        $gpsData = [
            'lat_ref'                    => 'N',
            'lat'                        => 52.0,
            'lon_ref'                    => 'E',
            'lon'                        => 13.0,
            'alt_ref'                    => null,
            'alt'                        => null,
            'version'                    => null,
            'version_raw'                => null,
            'satellites'                 => null,
            'status'                     => null,
            'measure_mode'               => null,
            'dop'                        => null,
            'speed_ref'                  => 'K',
            'speed_ms'                   => null,
            'speed_original_ref'         => 'K',
            'speed_original'             => 36.0,
            'track_ref'                  => null,
            'track'                      => null,
            'img_direction_ref'          => null,
            'img_direction'              => null,
            'map_datum'                  => null,
            'dest_lat_ref'               => null,
            'dest_lat'                   => null,
            'dest_lon_ref'               => null,
            'dest_lon'                   => null,
            'dest_bearing_ref'           => null,
            'dest_bearing'               => null,
            'dest_distance_ref'          => null,
            'dest_distance_m'            => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
            'processing_method'          => null,
            'area_information'           => null,
            'date'                       => null,
            'date_raw'                   => null,
            'time'                       => null,
            'timestamp'                  => null,
            'differential'               => null,
            'h_positioning_error'        => null,
        ];

        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('gps')->willReturn($gpsData);
        $exifDoc->method('gpsTimestamp')->willReturn(null);
        $exifDoc->method('gpsDateStamp')->willReturn(null);
        $exifDoc->method('gpsTimeStampString')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertNull($gps->speedMs);
        self::assertSame(36.0, $gps->speedOriginal);
    }

    #[Test]
    public function normalizesDateFormat(): void
    {
        $gpsData = [
            'lat_ref'                    => 'N',
            'lat'                        => 52.0,
            'lon_ref'                    => 'E',
            'lon'                        => 13.0,
            'alt_ref'                    => null,
            'alt'                        => null,
            'version'                    => null,
            'version_raw'                => null,
            'satellites'                 => null,
            'status'                     => null,
            'measure_mode'               => null,
            'dop'                        => null,
            'speed_ref'                  => null,
            'speed_ms'                   => null,
            'speed_original_ref'         => null,
            'speed_original'             => null,
            'track_ref'                  => null,
            'track'                      => null,
            'img_direction_ref'          => null,
            'img_direction'              => null,
            'map_datum'                  => null,
            'dest_lat_ref'               => null,
            'dest_lat'                   => null,
            'dest_lon_ref'               => null,
            'dest_lon'                   => null,
            'dest_bearing_ref'           => null,
            'dest_bearing'               => null,
            'dest_distance_ref'          => null,
            'dest_distance_m'            => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
            'processing_method'          => null,
            'area_information'           => null,
            'date'                       => '2023:06:15',
            'date_raw'                   => '2023:06:15',
            'time'                       => null,
            'timestamp'                  => null,
            'differential'               => null,
            'h_positioning_error'        => null,
        ];

        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('gps')->willReturn($gpsData);
        $exifDoc->method('gpsTimestamp')->willReturn(null);
        $exifDoc->method('gpsDateStamp')->willReturn(null);
        $exifDoc->method('gpsTimeStampString')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertInstanceOf(Gps::class, $gps);
        self::assertSame('2023-06-15', $gps->date);
    }
}
