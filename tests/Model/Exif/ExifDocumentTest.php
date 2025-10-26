<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Support\GpsTiffBuilder;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ExifDocument
 */
#[CoversClass(ExifDocument::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(GpsTiffBuilder::class)]
final class ExifDocumentTest extends TestCase
{
    private const string ISO_8601_MILLISECONDS = 'Y-m-d\TH:i:s.vP';

    /**
     * Ensures an Exif document exposes representative camera, exposure, and GPS metadata values.
     */
    #[Test]
    public function exposesRepresentativeExifValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 1, "Canon\0"),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 6),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            ExifTag::EXPOSURE_TIME            => new IfdEntry(
                ExifTag::EXPOSURE_TIME,
                5,
                1,
                new ExifRational(1, 125),
            ),
            ExifTag::F_NUMBER => new IfdEntry(
                ExifTag::F_NUMBER,
                5,
                1,
                new ExifRational(28, 10),
            ),
            ExifTag::FOCAL_LENGTH => new IfdEntry(
                ExifTag::FOCAL_LENGTH,
                5,
                1,
                new ExifRational(50, 1),
            ),
            ExifTag::LENS_MODEL             => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF50mm F1.2L USM'),
            ExifTag::DATETIME_ORIGINAL      => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '456'),
            ExifTag::OFFSET_TIME_ORIGINAL   => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(40, 1),
                    new ExifRational(26, 1),
                    new ExifRational(3000, 100),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(79, 1),
                    new ExifRational(58, 1),
                    new ExifRational(6000, 100),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(123, 1),
            ),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, $gpsIfd, null, null);

        self::assertSame('Canon', $doc->cameraMake());
        self::assertSame('EOS R5', $doc->cameraModel());
        self::assertSame('RF50mm F1.2L USM', $doc->lensModel());
        self::assertSame(6, $doc->orientation());
        self::assertSame(200, $doc->iso());
        self::assertSame(0.008, $doc->exposureTime());
        self::assertSame(2.8, $doc->fNumber());
        self::assertSame(50.0, $doc->focalLengthMm());
        self::assertSame('2024:05:01 12:34:56', $doc->dateTimeOriginalRaw());
        self::assertSame('+02:00', $doc->offsetTimeOriginalRaw());

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2024-05-01T12:34:56.123+02:00', $capture->format(self::ISO_8601_MILLISECONDS));

        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.441666, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(79.983333, $gps['lon'], 0.000001);
        self::assertEquals(123.0, $gps['alt']);
    }

    /**
     * Falls back to DateTimeDigitized when DateTimeOriginal is missing.
     */
    #[Test]
    public function fallsBackToDateTimeDigitizedWhenOriginalMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2015:06:07 08:09:10'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '234'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '-04:00'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2015-06-07T08:09:10.234-04:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    /**
     * Uses the legacy TimeZoneOffset tag when explicit offset tags are unavailable.
     */
    #[Test]
    public function usesTimeZoneOffsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL     => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2021:02:03 04:05:06'),
            ExifTag::SUB_SEC_TIME_ORIGINAL => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '789'),
            ExifTag::TIME_ZONE_OFFSET      => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                3,
                1,
                new ExifNumericList([9]),
            ),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2021-02-03T04:05:06.789+09:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    #[Test]
    public function usesSecondTimeZoneOffsetComponentForDigitizedFallback(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2022:08:09 10:11:12'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '321'),
            ExifTag::TIME_ZONE_OFFSET       => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                3,
                2,
                new ExifNumericList([9, -3]),
            ),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2022-08-09T10:11:12.321-03:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    /**
     * Uses the legacy ModifyDate tag when original and digitised timestamps are absent.
     */
    #[Test]
    public function usesModifyDateWhenOriginalAndDigitizedMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME => new IfdEntry(ExifTag::DATETIME, 2, 1, '2010:02:03 04:05:06'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 1, '12'),
            ExifTag::OFFSET_TIME  => new IfdEntry(ExifTag::OFFSET_TIME, 2, 1, '-05:00'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2010-02-03T04:05:06.120-05:00', $capture->format(self::ISO_8601_MILLISECONDS));
    }

    /**
     * Ensures EXIF 3.0 table 64 convenience accessors expose normalised values.
     */
    #[Test]
    public function exposesTable64ConvenienceAccessors(): void
    {
        $transferFunction    = new ExifNumericList([0, 32768, 65535]);
        $referenceBlackWhite = new ExifRationalList([
            new ExifRational(0, 1),
            new ExifRational(255, 1),
            new ExifRational(0, 1),
            new ExifRational(255, 1),
            new ExifRational(0, 1),
            new ExifRational(255, 1),
        ]);

        $ifd0 = new Ifd([
            ExifTag::TRANSFER_FUNCTION     => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 3, $transferFunction),
            ExifTag::REFERENCE_BLACK_WHITE => new IfdEntry(ExifTag::REFERENCE_BLACK_WHITE, 5, 6, $referenceBlackWhite),
            ExifTag::COPYRIGHT             => new IfdEntry(ExifTag::COPYRIGHT, 2, 9, "Jane Doe\0"),
        ]);

        $thumbnailIfd = new Ifd([
            ExifTag::STRIP_OFFSETS => new IfdEntry(
                ExifTag::STRIP_OFFSETS,
                4,
                2,
                new ExifNumericList([64, 128]),
            ),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(
                ExifTag::STRIP_BYTE_COUNTS,
                4,
                2,
                new ExifNumericList([256, 512]),
            ),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 4096),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, $thumbnailIfd);

        self::assertSame([64, 128], $doc->stripOffsets());
        self::assertSame([256, 512], $doc->stripByteCounts());
        self::assertSame([0, 32768, 65535], $doc->transferFunction());
        self::assertSame(4096, $doc->jpegThumbnailOffset());
        self::assertSame(8192, $doc->jpegThumbnailLength());
        self::assertSame([0.0, 255.0, 0.0, 255.0, 0.0, 255.0], $doc->referenceBlackWhite());
        self::assertSame('Jane Doe', $doc->copyright());
    }

    /**
     * Ensures the GPS helper exposes every decoded field from table 66 including references.
     */
    #[Test]
    public function exposesCompleteGpsMetadata(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(33, 1),
                    new ExifRational(52, 1),
                    new ExifRational(1234, 100),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(18, 1),
                    new ExifRational(24, 1),
                    new ExifRational(5678, 100),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(250, 1)),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(5, 1),
                    new ExifRational(6, 1),
                    new ExifRational(789, 100),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:07'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '07'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'V'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '2'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(15, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'N'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(12345, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'M'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(54321, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'T'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(90, 1)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 9, "WGS-84\0"),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(34, 1),
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
                    new ExifRational(19, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(45, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'M'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(100, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0SURVEY"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0Test Area"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 1),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(5, 10)),
        ]);

        $doc = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);

        $gps = $doc->gps();

        self::assertSame('S', $gps['lat_ref']);
        self::assertIsFloat($gps['lat']);
        self::assertEqualsWithDelta(-33.870094, $gps['lat'], 0.000001);
        self::assertSame('W', $gps['lon_ref']);
        self::assertIsFloat($gps['lon']);
        self::assertEqualsWithDelta(-18.415772, $gps['lon'], 0.000001);
        self::assertSame(1, $gps['alt_ref']);
        self::assertEqualsWithDelta(-250.0, $gps['alt'], 0.000001);

        self::assertSame('3.0.0.0', $gps['version']);
        self::assertSame('07', $gps['satellites']);
        self::assertSame('V', $gps['status']);
        self::assertSame('2', $gps['measure_mode']);
        self::assertIsFloat($gps['dop']);
        self::assertEqualsWithDelta(1.5, $gps['dop'], 0.000001);
        self::assertSame('N', $gps['speed_ref']);
        self::assertIsFloat($gps['speed_ms']);
        self::assertEqualsWithDelta(6.3508166667, $gps['speed_ms'], 0.000001);
        self::assertSame('M', $gps['track_ref']);
        self::assertIsFloat($gps['track']);
        self::assertEqualsWithDelta(183.21, $gps['track'], 0.000001);
        self::assertSame('T', $gps['img_direction_ref']);
        self::assertIsFloat($gps['img_direction']);
        self::assertEqualsWithDelta(90.0, $gps['img_direction'], 0.000001);
        self::assertSame('WGS-84', $gps['map_datum']);
        self::assertSame('N', $gps['dest_lat_ref']);
        self::assertIsFloat($gps['dest_lat']);
        self::assertEqualsWithDelta(34.0, $gps['dest_lat'], 0.000001);
        self::assertSame('E', $gps['dest_lon_ref']);
        self::assertIsFloat($gps['dest_lon']);
        self::assertEqualsWithDelta(19.0, $gps['dest_lon'], 0.000001);
        self::assertSame('T', $gps['dest_bearing_ref']);
        self::assertIsFloat($gps['dest_bearing']);
        self::assertEqualsWithDelta(45.0, $gps['dest_bearing'], 0.000001);
        self::assertSame('M', $gps['dest_distance_ref']);
        self::assertIsFloat($gps['dest_distance_m']);
        self::assertEqualsWithDelta(160934.4, $gps['dest_distance_m'], 0.000001);
        self::assertSame('SURVEY', $gps['processing_method']);
        self::assertSame('Test Area', $gps['area_information']);
        self::assertSame('2024-05-07', $gps['date']);
        self::assertSame('05:06:07.89', $gps['time']);

        $timestamp = $gps['timestamp'];
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-07T05:06:07+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(1, $gps['differential']);
        self::assertEqualsWithDelta(0.5, $gps['h_positioning_error'], 0.000001);

        self::assertIsFloat($doc->gpsSpeedMetresPerSecond());
        self::assertIsFloat($doc->gpsTrack());
        self::assertIsFloat($doc->gpsImgDirection());
        self::assertIsFloat($doc->gpsDestinationBearing());
        self::assertIsFloat($doc->gpsDestinationDistanceMetres());
        self::assertSame(1, $doc->gpsDifferential());
        self::assertIsFloat($doc->gpsHorizontalPositioningError());
    }

    /**
     * Ensures GPS speed conversion returns null when the unit reference is unknown.
     */
    #[Test]
    public function gpsSpeedMetresPerSecondRequiresKnownReference(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'X'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(5000, 100)),
        ]);

        $doc = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);

        self::assertNull($doc->gpsSpeedMetresPerSecond());
    }

    /**
     * Ensures destination distance conversion returns null when the unit reference is unknown.
     */
    #[Test]
    public function gpsDestinationDistanceRequiresKnownReference(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'Q'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(250, 1)),
        ]);

        $doc = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);

        self::assertNull($doc->gpsDestinationDistanceMetres());
    }

    /**
     * Ensures extended EXIF getters expose camera ownership, dimensions and exposure metadata.
     */
    #[Test]
    public function exposesExtendedExifValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE         => new IfdEntry(ExifTag::MAKE, 2, 1, 'Canon'),
            ExifTag::MODEL        => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R6'),
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 2667),
            ExifTag::DATETIME     => new IfdEntry(ExifTag::DATETIME, 2, 1, '2024:05:02 09:10:11'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_OWNER_NAME         => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, "Jane Doe\0"),
            ExifTag::BODY_SERIAL_NUMBER        => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, '123456789'),
            ExifTag::LENS_MODEL                => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF70-200mm'),
            ExifTag::LENS_SERIAL_NUMBER        => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LNS987654321'),
            ExifTag::PIXEL_X_DIMENSION         => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 4, 1, 5472),
            ExifTag::PIXEL_Y_DIMENSION         => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 4, 1, 3648),
            ExifTag::COLOR_SPACE               => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 1),
            ExifTag::IMAGE_UNIQUE_ID           => new IfdEntry(ExifTag::IMAGE_UNIQUE_ID, 2, 1, "UNIQUE-ID-123\0"),
            ExifTag::ISO_SPEED                 => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 800),
            ExifTag::EXPOSURE_TIME             => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, new ExifRational(1, 60)),
            ExifTag::F_NUMBER                  => new IfdEntry(ExifTag::F_NUMBER, 5, 1, new ExifRational(56, 10)),
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, new ExifRational(85, 1)),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::EXPOSURE_PROGRAM          => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 3),
            ExifTag::INTERLACE                 => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
            ExifTag::METERING_MODE             => new IfdEntry(ExifTag::METERING_MODE, 3, 1, 5),
            ExifTag::FLASH                     => new IfdEntry(ExifTag::FLASH, 3, 1, 0x5F),
            ExifTag::WHITE_BALANCE             => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
            ExifTag::EXPOSURE_BIAS_VALUE       => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, new ExifRational(-1, 2)),
            ExifTag::BRIGHTNESS_VALUE          => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, new ExifRational(55, 10)),
            ExifTag::MAX_APERTURE_VALUE        => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, new ExifRational(28, 10)),
            ExifTag::DATETIME_ORIGINAL         => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 09:10:11'),
            ExifTag::SUB_SEC_TIME_ORIGINAL     => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 1, '125'),
            ExifTag::OFFSET_TIME_ORIGINAL      => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+01:30'),
            ExifTag::DATETIME_DIGITIZED        => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:05:02 09:15:00'),
            ExifTag::SUB_SEC_TIME_DIGITIZED    => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '250'),
            ExifTag::OFFSET_TIME_DIGITIZED     => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+01:30'),
            ExifTag::OFFSET_TIME               => new IfdEntry(ExifTag::OFFSET_TIME, 2, 1, '+01:30'),
            ExifTag::SUB_SEC_TIME              => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 1, '500'),
            ExifTag::TIME_ZONE_OFFSET          => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-130])),
            ExifTag::SELF_TIMER_MODE           => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 10),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame('Jane Doe', $doc->ownerName());
        self::assertSame('123456789', $doc->bodySerialNumber());
        self::assertSame('LNS987654321', $doc->lensSerialNumber());
        self::assertSame(5472, $doc->imageWidth());
        self::assertSame(3648, $doc->imageHeight());
        self::assertSame(1, $doc->colorSpace());
        self::assertSame('UNIQUE-ID-123', $doc->imageUniqueId());
        self::assertSame(800, $doc->iso());
        self::assertEqualsWithDelta(0.016666666666667, $doc->exposureTime(), 0.000000000000001);
        self::assertEqualsWithDelta(5.6, $doc->fNumber(), 0.000000000000001);
        self::assertEqualsWithDelta(85.0, $doc->focalLengthMm(), 0.000000000000001);
        self::assertSame(85, $doc->focalLength35Mm());
        self::assertSame(3, $doc->exposureProgram());
        self::assertSame(5, $doc->meteringMode());
        self::assertSame(0x5F, $doc->flash());
        self::assertSame(1, $doc->whiteBalance());
        self::assertEqualsWithDelta(-0.5, $doc->exposureBias(), 0.000000000000001);
        self::assertEqualsWithDelta(5.5, $doc->brightnessValue(), 0.000000000000001);
        self::assertEqualsWithDelta(2.8, $doc->maxApertureApex(), 0.000000000000001);
        self::assertSame('500', $doc->subSecTime());
        self::assertSame('125', $doc->subSecTimeOriginal());
        self::assertSame('250', $doc->subSecTimeDigitized());
        self::assertSame('+01:30', $doc->offsetTime());
        self::assertSame('+01:30', $doc->offsetTimeOriginal());
        self::assertSame('+01:30', $doc->offsetTimeDigitized());
        self::assertSame([-90], $doc->timeZoneOffsetMinutes());
        self::assertSame(10, $doc->selfTimerModeSeconds());
        self::assertSame(1, $doc->interlace());

        $captured = $doc->captureDateTime();
        self::assertNotNull($captured);
        self::assertSame('2024-05-02T09:10:11.125+01:30', $captured->format(self::ISO_8601_MILLISECONDS));

        $digitized = $doc->dateTimeDigitized();
        self::assertNotNull($digitized);
        self::assertSame('2024-05-02T09:15:00.250+01:30', $digitized->format(self::ISO_8601_MILLISECONDS));

        $fileDate = $doc->dateTime();
        self::assertNotNull($fileDate);
        self::assertSame('2024-05-02T09:10:11.500+01:30', $fileDate->format(self::ISO_8601_MILLISECONDS));
    }

    /**
     * Ensures Table 65 extension tags are exposed via dedicated getters.
     */
    #[Test]
    public function exposesTable65Extensions(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Coastal cliffs'),
            ExifTag::IMAGE_TITLE       => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Cliffside Dusk'),
            ExifTag::PHOTOGRAPHER      => new IfdEntry(ExifTag::PHOTOGRAPHER, 2, 1, 'Alex Light'),
            ExifTag::IMAGE_EDITOR      => new IfdEntry(ExifTag::IMAGE_EDITOR, 2, 1, 'Chris Edit'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COMPONENTS_CONFIGURATION    => new IfdEntry(ExifTag::COMPONENTS_CONFIGURATION, 7, 4, new ExifNumericList([1, 2, 3, 0])),
            ExifTag::COMPRESSED_BITS_PER_PIXEL   => new IfdEntry(ExifTag::COMPRESSED_BITS_PER_PIXEL, 5, 1, new ExifRational(45, 10)),
            ExifTag::USER_COMMENT                => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, "ASCII\0\0\0Calibrated output\0"),
            ExifTag::SPECTRAL_SENSITIVITY        => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 1, 'Spectral A'),
            ExifTag::OECF                        => new IfdEntry(ExifTag::OECF, 7, 1, 'OECF Blob'),
            ExifTag::ISO_SPEED_LATITUDE_YYY      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 3, 1, 200),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 3, 1, 400),
            ExifTag::IMAGE_NUMBER                => new IfdEntry(ExifTag::IMAGE_NUMBER, 3, 1, 512),
            ExifTag::SECURITY_CLASSIFICATION     => new IfdEntry(ExifTag::SECURITY_CLASSIFICATION, 2, 1, 'Confidential'),
            ExifTag::IMAGE_HISTORY               => new IfdEntry(ExifTag::IMAGE_HISTORY, 2, 1, 'Processed in RawLab'),
            ExifTag::TEMPERATURE                 => new IfdEntry(ExifTag::TEMPERATURE, 10, 1, new ExifRational(200, 10)),
            ExifTag::HUMIDITY                    => new IfdEntry(ExifTag::HUMIDITY, 10, 1, new ExifRational(550, 10)),
            ExifTag::PRESSURE                    => new IfdEntry(ExifTag::PRESSURE, 10, 1, new ExifRational(100000, 100)),
            ExifTag::BATTERY_LEVEL               => new IfdEntry(ExifTag::BATTERY_LEVEL, 5, 1, new ExifRational(3, 4)),
            ExifTag::WATER_DEPTH                 => new IfdEntry(ExifTag::WATER_DEPTH, 10, 1, new ExifRational(30, 10)),
            ExifTag::ACCELERATION                => new IfdEntry(ExifTag::ACCELERATION, 10, 1, new ExifRational(10, 1)),
            ExifTag::CAMERA_ELEVATION_ANGLE      => new IfdEntry(ExifTag::CAMERA_ELEVATION_ANGLE, 10, 1, new ExifRational(50, 10)),
            ExifTag::RELATED_SOUND_FILE          => new IfdEntry(ExifTag::RELATED_SOUND_FILE, 2, 1, 'clip.wav'),
            ExifTag::FLASH_ENERGY                => new IfdEntry(ExifTag::FLASH_ENERGY, 5, 1, new ExifRational(150, 10)),
            ExifTag::SPATIAL_FREQUENCY_RESPONSE  => new IfdEntry(ExifTag::SPATIAL_FREQUENCY_RESPONSE, 7, 1, 'SFR Data'),
            ExifTag::FOCAL_PLANE_X_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_X_RESOLUTION, 5, 1, new ExifRational(8000, 100)),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_Y_RESOLUTION, 5, 1, new ExifRational(7900, 100)),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => new IfdEntry(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 3, 1, 2),
            ExifTag::TIFF_EP_STANDARD_ID         => new IfdEntry(ExifTag::TIFF_EP_STANDARD_ID, 1, 4, new ExifNumericList([2, 0, 0, 0])),
            ExifTag::SUBJECT_LOCATION            => new IfdEntry(ExifTag::SUBJECT_LOCATION, 3, 2, new ExifNumericList([1024, 768])),
            ExifTag::EXPOSURE_INDEX              => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(320, 1)),
            ExifTag::SCENE_TYPE                  => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, chr(1)),
            ExifTag::CFA_PATTERN                 => new IfdEntry(ExifTag::CFA_PATTERN, 7, 4, "\x02\x01\x01\x02"),
            ExifTag::CUSTOM_RENDERED             => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
            ExifTag::DEVICE_SETTING_DESCRIPTION  => new IfdEntry(ExifTag::DEVICE_SETTING_DESCRIPTION, 7, 1, 'Neutral profile'),
            ExifTag::CAMERA_FIRMWARE             => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW 2.0'),
            ExifTag::RAW_DEVELOPING_SOFTWARE     => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'RawLab'),
            ExifTag::IMAGE_EDITING_SOFTWARE      => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'EditLab'),
            ExifTag::METADATA_EDITING_SOFTWARE   => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'MetaLab'),
            ExifTag::CAMERA_FIRMWARE_LEGACY      => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'FW Legacy'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame([1, 2, 3, 0], $doc->componentsConfiguration());
        self::assertSame(['Y', 'Cb', 'Cr', '-'], $doc->componentsConfigurationLabels());
        self::assertSame(4.5, $doc->compressedBitsPerPixel());
        self::assertSame('Calibrated output', $doc->userComment());
        self::assertSame('Spectral A', $doc->spectralSensitivity());
        self::assertSame('OECF Blob', $doc->oecf());
        self::assertSame(200, $doc->isoSpeedLatitudeYyy());
        self::assertSame(400, $doc->isoSpeedLatitudeZzz());
        self::assertSame(512, $doc->imageNumber());
        self::assertSame('Confidential', $doc->securityClassification());
        self::assertSame('Processed in RawLab', $doc->imageHistory());
        self::assertEqualsWithDelta(20.0, $doc->temperatureCelsius(), 0.0001);
        self::assertEqualsWithDelta(75.0, $doc->batteryLevelPercent() ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(55.0, $doc->humidityPercent(), 0.0001);
        self::assertEqualsWithDelta(1000.0, $doc->pressureHPa(), 0.0001);
        self::assertEqualsWithDelta(3.0, $doc->waterDepthMeters(), 0.0001);
        self::assertEqualsWithDelta(10.0, $doc->accelerationMs2(), 0.0001);
        self::assertEqualsWithDelta(5.0, $doc->cameraElevationAngleDeg(), 0.0001);
        self::assertSame('clip.wav', $doc->relatedSoundFile());
        self::assertEqualsWithDelta(15.0, $doc->flashEnergy() ?? 0.0, 0.0001);
        self::assertSame('SFR Data', $doc->spatialFrequencyResponse());
        self::assertEqualsWithDelta(80.0, $doc->focalPlaneXResolution() ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(79.0, $doc->focalPlaneYResolution() ?? 0.0, 0.0001);
        self::assertSame(2, $doc->focalPlaneResolutionUnit());
        self::assertSame([2, 0, 0, 0], $doc->tiffEpStandardId());
        self::assertSame([1024, 768], $doc->subjectLocation());
        self::assertSame(320.0, $doc->exposureIndex());
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $doc->sceneType());
        self::assertSame([2, 1, 1, 2], $doc->cfaPattern());
        self::assertSame([
            CfaPatternColor::BLUE,
            CfaPatternColor::GREEN,
            CfaPatternColor::GREEN,
            CfaPatternColor::BLUE,
        ], $doc->cfaPatternColors());
        self::assertSame(CustomRendered::CUSTOM_PROCESS, $doc->customRendered());
        self::assertSame('Neutral profile', $doc->deviceSettingDescription());
        self::assertSame('Cliffside Dusk', $doc->imageTitle());
        self::assertSame('Alex Light', $doc->photographer());
        self::assertSame('Chris Edit', $doc->imageEditor());
        self::assertSame('FW 2.0', $doc->cameraFirmware());
        self::assertSame('RawLab', $doc->rawDevelopingSoftware());
        self::assertSame('EditLab', $doc->imageEditingSoftware());
        self::assertSame('MetaLab', $doc->metadataEditingSoftware());
    }

    /**
     * Ensures image dimension getters fall back to values stored within IFD0 when EXIF tags are absent.
     */
    #[Test]
    public function imageDimensionsFallbackToIfd0(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 768),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(1024, $doc->imageWidth());
        self::assertSame(768, $doc->imageHeight());
    }

    #[Test]
    public function textualSoftwareTagsFallbackToLegacyIdentifiers(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_TITLE_LEGACY  => new IfdEntry(ExifTag::IMAGE_TITLE_LEGACY, 2, 1, 'Legacy Title'),
            ExifTag::PHOTOGRAPHER_LEGACY => new IfdEntry(ExifTag::PHOTOGRAPHER_LEGACY, 2, 1, 'Legacy Photographer'),
            ExifTag::IMAGE_EDITOR_LEGACY => new IfdEntry(ExifTag::IMAGE_EDITOR_LEGACY, 2, 1, 'Legacy Editor'),
            ExifTag::ARTIST              => new IfdEntry(ExifTag::ARTIST, 2, 1, 'Fallback Artist'),
            ExifTag::IMAGE_DESCRIPTION   => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'Fallback Description'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_LEGACY, 2, 1, 'Legacy FW'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY, 2, 1, 'Legacy Raw'),
            ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Edit'),
            ExifTag::METADATA_EDITING_SOFTWARE_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_LEGACY, 2, 1, 'Legacy Meta'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame('Legacy Title', $doc->imageTitle());
        self::assertSame('Legacy Photographer', $doc->photographer());
        self::assertSame('Legacy Editor', $doc->imageEditor());
        self::assertSame('Legacy FW', $doc->cameraFirmware());
        self::assertSame('Legacy Raw', $doc->rawDevelopingSoftware());
        self::assertSame('Legacy Edit', $doc->imageEditingSoftware());
        self::assertSame('Legacy Meta', $doc->metadataEditingSoftware());
    }

    #[Test]
    public function textualSoftwareVersionsUseLegacyTags(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY           => new IfdEntry(ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY, 2, 1, 'FW 3.1.0'),
            ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY, 2, 1, 'RawLab 5.2.1'),
            ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY, 2, 1, 'MetaLab 1.0.0'),
        ]);

        $doc = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('FW 3.1.0', $doc->cameraFirmwareVersion());
        self::assertSame('RawLab 5.2.1', $doc->rawDevelopingSoftwareVersion());
        self::assertSame('MetaLab 1.0.0', $doc->metadataEditingSoftwareVersion());
    }

    #[Test]
    public function exifThreeOmitsLegacySoftwareVersions(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_TITLE => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Autumn Sunset'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION               => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::CAMERA_FIRMWARE            => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'Firmware Build 5'),
            ExifTag::RAW_DEVELOPING_SOFTWARE    => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'Raw Developer X'),
            ExifTag::IMAGE_EDITING_SOFTWARE     => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Image Editor Y'),
            ExifTag::METADATA_EDITING_SOFTWARE  => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Metadata Tool Z'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame('3.00', $doc->exifVersion());
        self::assertSame('3.0', $doc->exifProfile());
        self::assertSame('Autumn Sunset', $doc->imageTitle());
        self::assertSame('Firmware Build 5', $doc->cameraFirmware());
        self::assertNull($doc->cameraFirmwareVersion());
        self::assertSame('Raw Developer X', $doc->rawDevelopingSoftware());
        self::assertNull($doc->rawDevelopingSoftwareVersion());
        self::assertSame('Image Editor Y', $doc->imageEditingSoftware());
        self::assertSame('Metadata Tool Z', $doc->metadataEditingSoftware());
        self::assertNull($doc->metadataEditingSoftwareVersion());
    }

    /**
     * Ensures the ISO getter prefers EXIF 3.0 sensitivity tags before falling back to photographic sensitivity.
     */
    #[Test]
    public function isoPrefersStandardOutputAndRecommendedIndices(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 100),
        ]);

        $exifIfd = new Ifd([
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 3, 1, 160),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX  => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);
        self::assertSame(160, $doc->iso());

        $recommendedExifIfd = new Ifd([
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY   => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $docWithRecommended = new ExifDocument($ifd0, $recommendedExifIfd, null, null, null);
        self::assertSame(320, $docWithRecommended->iso());

        $photographicExifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $docWithPhotographic = new ExifDocument($ifd0, $photographicExifIfd, null, null, null);
        self::assertSame(640, $docWithPhotographic->iso());
    }

    /**
     * Ensures the ISO getter falls back to legacy tags when the ISO speed tag is missing.
     */
    #[Test]
    public function isoFallsBackToLegacyTags(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 320),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame(320, $doc->iso());

        $docWithoutExif = new ExifDocument($ifd0, null, null, null, null);
        self::assertSame(200, $docWithoutExif->iso());
    }

    /**
     * Ensures GPS metadata parsed via the TIFF reader is normalised and exposed via dedicated helpers.
     */
    #[Test]
    public function parsesGpsMetadataFromSyntheticTiff(): void
    {
        $document = (new TiffExifReader())->parseFromBlob(GpsTiffBuilder::buildClassicGpsTiff());

        $gps = $document->gps();

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

        self::assertSame('K', $document->gpsSpeedRef());
        self::assertEqualsWithDelta(20.0, $document->gpsSpeedMetresPerSecond(), 0.000001);
        self::assertSame('T', $document->gpsTrackRef());
        self::assertEqualsWithDelta(90.0, $document->gpsTrack(), 0.000001);
        self::assertSame('M', $document->gpsImgDirectionRef());
        self::assertEqualsWithDelta(45.0, $document->gpsImgDirection(), 0.000001);
        self::assertSame('T', $document->gpsDestinationBearingRef());
        self::assertEqualsWithDelta(45.0, $document->gpsDestinationBearing(), 0.000001);
        self::assertSame('K', $document->gpsDestinationDistanceRef());
        self::assertEqualsWithDelta(42000.0, $document->gpsDestinationDistanceMetres(), 0.000001);
        self::assertSame('2024-05-06', $document->gpsDateStamp());
        self::assertSame('12:34:56.789', $document->gpsTimeStampString());

        $timestamp = $document->gpsTimestamp();
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));

        self::assertSame(2, $document->gpsDifferential());
        self::assertEqualsWithDelta(1.5, $document->gpsHorizontalPositioningError(), 0.000001);
    }
}
