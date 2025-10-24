<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
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
final class ExifDocumentTest extends TestCase
{
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
            ExifTag::LENS_MODEL           => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF50mm F1.2L USM'),
            ExifTag::DATETIME_ORIGINAL    => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
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
        self::assertSame('2024-05-01T12:34:56+02:00', $capture->format(DATE_ATOM));

        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.441666, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(79.983333, $gps['lon'], 0.000001);
        self::assertEquals(123.0, $gps['alt']);
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
            ExifTag::EXIF_IMAGE_WIDTH          => new IfdEntry(ExifTag::EXIF_IMAGE_WIDTH, 4, 1, 5472),
            ExifTag::EXIF_IMAGE_HEIGHT         => new IfdEntry(ExifTag::EXIF_IMAGE_HEIGHT, 4, 1, 3648),
            ExifTag::COLOR_SPACE               => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 1),
            ExifTag::IMAGE_UNIQUE_ID           => new IfdEntry(ExifTag::IMAGE_UNIQUE_ID, 2, 1, "UNIQUE-ID-123\0"),
            ExifTag::ISO_SPEED                 => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 800),
            ExifTag::EXPOSURE_TIME             => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, new ExifRational(1, 60)),
            ExifTag::F_NUMBER                  => new IfdEntry(ExifTag::F_NUMBER, 5, 1, new ExifRational(56, 10)),
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, new ExifRational(85, 1)),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::EXPOSURE_PROGRAM          => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 3),
            ExifTag::METERING_MODE             => new IfdEntry(ExifTag::METERING_MODE, 3, 1, 5),
            ExifTag::FLASH                     => new IfdEntry(ExifTag::FLASH, 3, 1, 0x5F),
            ExifTag::WHITE_BALANCE             => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
            ExifTag::EXPOSURE_BIAS_VALUE       => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, new ExifRational(-1, 2)),
            ExifTag::BRIGHTNESS_VALUE          => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, new ExifRational(55, 10)),
            ExifTag::MAX_APERTURE_VALUE        => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, new ExifRational(28, 10)),
            ExifTag::DATETIME_ORIGINAL         => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 09:10:11'),
            ExifTag::OFFSET_TIME_ORIGINAL      => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+01:30'),
            ExifTag::DATETIME_DIGITIZED        => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:05:02 09:15:00'),
            ExifTag::OFFSET_TIME_DIGITIZED     => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+01:30'),
            ExifTag::OFFSET_TIME               => new IfdEntry(ExifTag::OFFSET_TIME, 2, 1, '+01:30'),
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

        $captured = $doc->captureDateTime();
        self::assertNotNull($captured);
        self::assertSame('2024-05-02T09:10:11+01:30', $captured->format(DATE_ATOM));

        $digitized = $doc->dateTimeDigitized();
        self::assertNotNull($digitized);
        self::assertSame('2024-05-02T09:15:00+01:30', $digitized->format(DATE_ATOM));

        $general = $doc->dateTime();
        self::assertNotNull($general);
        self::assertSame('2024-05-02T09:10:11+01:30', $general->format(DATE_ATOM));

        self::assertSame('2024:05:02 09:10:11', $doc->dateTimeRaw());
        self::assertSame('2024:05:02 09:15:00', $doc->dateTimeDigitizedRaw());
        self::assertSame('+01:30', $doc->offsetTimeRaw());
        self::assertSame('+01:30', $doc->offsetTimeDigitizedRaw());
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
}
