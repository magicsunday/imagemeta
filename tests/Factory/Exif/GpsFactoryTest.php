<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Exif;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Factory\Exif\GpsFactory;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(GpsFactory::class)]
final class GpsFactoryTest extends TestCase
{
    /**
     * Verifies that $gps->latitude equals 52.520008.
     *
     * @return void
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: GpsLatLonRef::NORTH,
            lat: 52.520008,
            lonRef: GpsLatLonRef::EAST,
            lon: 13.404954,
            altitudeRef: GpsAltitudeRef::ABOVE_SEA_LEVEL,
            altitude: 35.0,
            version: '2.4.0.0',
            satellites: '10',
            status: GpsStatus::MEASUREMENT_IN_PROGRESS,
            measureMode: GpsMeasureMode::THREE_DIMENSIONAL,
            dop: 1.5,
            speedRef: GpsSpeedRef::KILOMETERS_PER_HOUR,
            speedMs: 5.0,
            track: 90.0,
            mapDatum: 'WGS-84',
            processingMethod: 'GPS',
            areaInformation: 'Berlin',
            date: '2023-06-15',
            time: '14:30:00',
            differential: 0,
            hPositioningError: 3.0,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame(52.520008, $gps->latitude);
        self::assertSame(13.404954, $gps->longitude);
        self::assertSame(GpsLatLonRef::NORTH, $gps->latitudeRef);
        self::assertSame(GpsLatLonRef::EAST, $gps->longitudeRef);
        self::assertSame(35.0, $gps->altitude);
        self::assertSame(GpsAltitudeRef::ABOVE_SEA_LEVEL, $gps->altitudeRef);
        self::assertSame('2.4.0.0', $gps->version);
        self::assertSame('10', $gps->satellites);
        self::assertSame(GpsStatus::MEASUREMENT_IN_PROGRESS, $gps->status);
        self::assertSame(GpsMeasureMode::THREE_DIMENSIONAL, $gps->measureMode);
        self::assertSame(1.5, $gps->dop);
        self::assertSame(GpsSpeedRef::KILOMETERS_PER_HOUR, $gps->speedRef);
        self::assertSame(5.0 / 3.6, $gps->speedMs);
        self::assertSame(90.0, $gps->track);
        self::assertSame('WGS-84', $gps->mapDatum);
        self::assertSame('GPS', $gps->processingMethod);
        self::assertSame('Berlin', $gps->areaInformation);
        self::assertSame('2023-06-15', $gps->date);
        self::assertSame('14:30:00', $gps->time);
        self::assertInstanceOf(DateTimeImmutable::class, $gps->timestamp);
        self::assertSame(GpsDifferential::NO_CORRECTION, $gps->differential);
        self::assertSame(3.0, $gps->horizontalPositioningError);
    }

    /**
     * Verifies that $gps->latitude is null.
     *
     * @return void
     */
    #[Test]
    public function createsEmptyGpsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->latitude);
        self::assertNull($gps->longitude);
    }

    /**
     * Verifies that $gps->speedRef equals GpsSpeedRef::KILOMETERS_PER_HOUR.
     *
     * @return void
     */
    #[Test]
    public function convertsSpeedToMetresPerSecond(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: GpsSpeedRef::KILOMETERS_PER_HOUR,
            speedMs: 36.0,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame(GpsSpeedRef::KILOMETERS_PER_HOUR, $gps->speedRef);
        self::assertSame(36.0 / 3.6, $gps->speedMs);
    }

    /**
     * Verifies that $gps->date equals '2023-06-15'.
     *
     * @return void
     */
    #[Test]
    public function normalizesDateFormat(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: '2023:06:15',
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame('2023-06-15', $gps->date);
    }

    private function parsedExif(
        ?GpsLatLonRef $latRef,
        ?float $lat,
        ?GpsLatLonRef $lonRef,
        ?float $lon,
        ?GpsAltitudeRef $altitudeRef,
        ?float $altitude,
        ?string $version,
        ?string $satellites,
        ?GpsStatus $status,
        ?GpsMeasureMode $measureMode,
        ?float $dop,
        ?GpsSpeedRef $speedRef,
        ?float $speedMs,
        ?float $track,
        ?string $mapDatum,
        ?string $processingMethod,
        ?string $areaInformation,
        ?string $date,
        ?string $time,
        ?int $differential,
        ?float $hPositioningError,
    ): ParsedExif {
        $gpsEntries = [];

        if ($latRef instanceof GpsLatLonRef) {
            $gpsEntries[ExifTag::GPS_LATITUDE_REF] = new IfdEntry(
                ExifTag::GPS_LATITUDE_REF,
                2,
                2,
                $latRef->value,
            );
        }

        if ($lat !== null) {
            // Store as three SRATIONALs: deg, min, sec*1000
            $deg      = floor($lat);
            $minFloat = ($lat - $deg) * 60.0;
            $min      = floor($minFloat);
            $sec      = ($minFloat - $min) * 60.0;

            $pairs = [
                [$deg, 1],
                [$min, 1],
                [$sec * 1000, 1000],
            ];

            $gpsEntries[ExifTag::GPS_LATITUDE] = new IfdEntry(
                ExifTag::GPS_LATITUDE,
                10,
                3,
                $pairs,
            );
        }

        if ($lonRef instanceof GpsLatLonRef) {
            $gpsEntries[ExifTag::GPS_LONGITUDE_REF] = new IfdEntry(
                ExifTag::GPS_LONGITUDE_REF,
                2,
                2,
                $lonRef->value,
            );
        }

        if ($lon !== null) {
            $deg      = floor($lon);
            $minFloat = ($lon - $deg) * 60.0;
            $min      = floor($minFloat);
            $sec      = ($minFloat - $min) * 60.0;

            $pairs = [
                [$deg, 1],
                [$min, 1],
                [$sec * 1000, 1000],
            ];

            $gpsEntries[ExifTag::GPS_LONGITUDE] = new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                10,
                3,
                $pairs,
            );
        }

        if ($altitudeRef instanceof GpsAltitudeRef) {
            $gpsEntries[ExifTag::GPS_ALTITUDE_REF] = new IfdEntry(
                ExifTag::GPS_ALTITUDE_REF,
                1,
                1,
                $altitudeRef->value,
            );
        }

        if ($altitude !== null) {
            $gpsEntries[ExifTag::GPS_ALTITUDE] = new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                $altitude,
            );
        }

        if ($version !== null) {
            $versionParts                        = array_map(intval(...), explode('.', $version));
            $gpsEntries[ExifTag::GPS_VERSION_ID] = new IfdEntry(
                ExifTag::GPS_VERSION_ID,
                1,
                4,
                new ExifNumericList($versionParts),
            );
        }

        if ($satellites !== null) {
            $gpsEntries[ExifTag::GPS_SATELLITES] = new IfdEntry(
                ExifTag::GPS_SATELLITES,
                2,
                strlen($satellites),
                $satellites,
            );
        }

        if ($status instanceof GpsStatus) {
            $gpsEntries[ExifTag::GPS_STATUS] = new IfdEntry(
                ExifTag::GPS_STATUS,
                2,
                2,
                $status->value,
            );
        }

        if ($measureMode instanceof GpsMeasureMode) {
            $gpsEntries[ExifTag::GPS_MEASURE_MODE] = new IfdEntry(
                ExifTag::GPS_MEASURE_MODE,
                2,
                2,
                $measureMode->value,
            );
        }

        if ($dop !== null) {
            $gpsEntries[ExifTag::GPS_DOP] = new IfdEntry(
                ExifTag::GPS_DOP,
                5,
                1,
                $dop,
            );
        }

        if ($speedRef instanceof GpsSpeedRef) {
            $gpsEntries[ExifTag::GPS_SPEED_REF] = new IfdEntry(
                ExifTag::GPS_SPEED_REF,
                2,
                2,
                $speedRef->value,
            );
        }

        if ($speedMs !== null) {
            // Store directly as metres per second in GPS speed tag (unit via ref)
            $gpsEntries[ExifTag::GPS_SPEED] = new IfdEntry(
                ExifTag::GPS_SPEED,
                5,
                1,
                $speedMs,
            );
        }

        if ($track !== null) {
            $gpsEntries[ExifTag::GPS_TRACK] = new IfdEntry(
                ExifTag::GPS_TRACK,
                5,
                1,
                $track,
            );
        }

        if ($mapDatum !== null) {
            $gpsEntries[ExifTag::GPS_MAP_DATUM] = new IfdEntry(
                ExifTag::GPS_MAP_DATUM,
                2,
                strlen($mapDatum),
                $mapDatum,
            );
        }

        if ($processingMethod !== null) {
            $gpsEntries[ExifTag::GPS_PROCESSING_METHOD] = new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($processingMethod),
                $processingMethod,
            );
        }

        if ($areaInformation !== null) {
            $gpsEntries[ExifTag::GPS_AREA_INFORMATION] = new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                strlen($areaInformation),
                $areaInformation,
            );
        }

        if ($date !== null) {
            $dateStamp = str_replace('-', ':', $date);

            $gpsEntries[ExifTag::GPS_DATE_STAMP] = new IfdEntry(
                ExifTag::GPS_DATE_STAMP,
                2,
                strlen($dateStamp),
                $dateStamp,
            );
        }

        if ($time !== null) {
            // We directly store formatted time string in a custom helper tag
            $gpsEntries[ExifTag::GPS_TIME_STAMP] = new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                [
                    [14, 1],
                    [30, 1],
                    [0, 1],
                ],
            );
        }

        if ($differential !== null) {
            $gpsEntries[ExifTag::GPS_DIFFERENTIAL] = new IfdEntry(
                ExifTag::GPS_DIFFERENTIAL,
                3,
                1,
                $differential,
            );
        }

        if ($hPositioningError !== null) {
            $gpsEntries[ExifTag::GPS_H_POSITIONING_ERROR] = new IfdEntry(
                ExifTag::GPS_H_POSITIONING_ERROR,
                5,
                1,
                $hPositioningError,
            );
        }

        $ifd0   = new Ifd([]);
        $gpsIfd = new Ifd($gpsEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: new Ifd([]),
            gpsIfd: $gpsIfd,
            interopIfd: null,
            ifd1: null,
        );
    }
}
