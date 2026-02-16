<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Factory\GpsFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function strlen;

/**
 * Exercises GpsFactory for mapping EXIF GPS tags into the Gps value object.
 * It verifies coordinates, altitude, speed, and timestamp fields are derived correctly.
 * The suite covers enum conversions for references, status, and measurement modes.
 * This ensures GPS metadata is normalized consistently from EXIF inputs.
 *
 * @internal
 */
#[CoversClass(GpsFactory::class)]
#[UsesClass(XmpDocument::class)]
final class GpsFactoryTest extends TestCase
{
    /**
     * Supplies ParsedExif GPS tags covering coordinates, altitude, speed, and timestamp.
     * Verifies GpsFactory maps fields correctly and computes derived values like speed in m/s.
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
            processingMethod: "ASCII\0\0\0GPS",
            areaInformation: "ASCII\0\0\0Berlin",
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
     * Builds Metadata without an EXIF document.
     * Ensures the GPS value object contains null coordinates when no data is available.
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
     * Provides a GPS speed in kilometers per hour.
     * Confirms the factory converts the speed to metres per second.
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
     * Uses the EXIF GPS date format with colon separators.
     * Ensures the factory normalizes the date to ISO-style YYYY-MM-DD.
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

    /**
     * XMP altitude with ref 3 (below sea level) yields negative altitude.
     */
    #[Test]
    #[DataProvider('provideXmpNegativeAltitudeRefs')]
    public function xmpAltitudeWithBelowRefYieldsNegative(int $ref): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSAltitudeRef', self::NS_EXIF) => (string) $ref,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame(-100.0, $gps->altitude);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideXmpNegativeAltitudeRefs(): iterable
    {
        yield 'ref 1 (below ellipsoidal)' => [1];
        yield 'ref 3 (below sea level)' => [3];
    }

    /**
     * XMP altitude with ref 0/2 (above) yields positive altitude.
     */
    #[Test]
    #[DataProvider('provideXmpPositiveAltitudeRefs')]
    public function xmpAltitudeWithAboveRefYieldsPositive(int $ref): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSAltitudeRef', self::NS_EXIF) => (string) $ref,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame(100.0, $gps->altitude);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideXmpPositiveAltitudeRefs(): iterable
    {
        yield 'ref 0 (above ellipsoidal)' => [0];
        yield 'ref 2 (above sea level)' => [2];
    }

    /**
     * XMP speed with invalid ref does not produce a converted m/s value.
     */
    #[Test]
    public function xmpSpeedWithInvalidRefYieldsNullSpeed(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '50.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'X',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->speedMs);
    }

    /**
     * XMP speed with valid ref K yields correct m/s conversion.
     */
    #[Test]
    public function xmpSpeedWithKilometresRefYieldsMetresPerSecond(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '36.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'K',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame(36.0 / 3.6, $gps->speedMs);
    }

    /**
     * XMP coordinate with missing ref does not produce a parsed value.
     */
    #[Test]
    public function xmpCoordinateWithMissingRefYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF) => '52,31,15',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->latitude);
    }

    /**
     * XMP coordinate with invalid ref does not produce a parsed value.
     */
    #[Test]
    public function xmpCoordinateWithInvalidRefYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'X',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->latitude);
    }

    /**
     * XMP coordinate with valid ref parses correctly.
     */
    #[Test]
    public function xmpCoordinateWithValidRefParsesCorrectly(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertEqualsWithDelta(52.520833, $gps->latitude, 0.001);
    }

    /**
     * XMP DMS coordinate with minutes >= 60 yields null.
     */
    #[Test]
    public function xmpDmsWithMinutesOutOfRangeYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,61,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->latitude);
    }

    /**
     * XMP DMS coordinate with seconds >= 60 yields null.
     */
    #[Test]
    public function xmpDmsWithSecondsOutOfRangeYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,60',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->latitude);
    }

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

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
