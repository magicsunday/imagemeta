<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Adapter;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Adapter\CameraMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\DeviceMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\ExposureMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\GpsMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\ImageMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\LensMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\TemporalMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the EXIF metadata adapter layer built on top of ParsedExif.
 * It verifies facade accessors return the expected adapter types.
 * The suite checks delegation for camera, lens, exposure, temporal, device, image, and GPS fields.
 * This keeps the adapter API stable for domain-focused metadata access.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[CoversClass(CameraMetadataAdapter::class)]
#[CoversClass(LensMetadataAdapter::class)]
#[CoversClass(ExposureMetadataAdapter::class)]
#[CoversClass(DeviceMetadataAdapter::class)]
#[CoversClass(ImageMetadataAdapter::class)]
#[CoversClass(TemporalMetadataAdapter::class)]
#[CoversClass(GpsMetadataAdapter::class)]
final class MetadataAdaptersTest extends TestCase
{
    /**
     * Builds a ParsedExif document with representative metadata across all adapter domains.
     * Ensures facade methods return the correct adapter classes and delegated values.
     *
     * @return void
     */
    #[Test]
    public function adaptersExposeDomainMetadataThroughParsedExifFacade(): void
    {
        $parsedExif = $this->parsedExifWithMetadata();

        $cameraAdapter = $parsedExif->cameraMetadata();
        self::assertSame('MagicSunday', $cameraAdapter->make());
        self::assertSame('Photon X', $cameraAdapter->model());
        self::assertSame('BODY-123', $cameraAdapter->bodySerialNumber());
        self::assertSame('FW-2.1.0', $cameraAdapter->firmware());

        $lensAdapter = $parsedExif->lensMetadata();
        self::assertSame('Optic Corp', $lensAdapter->make());
        self::assertSame('24-70mm', $lensAdapter->model());
        self::assertSame('LENS-456', $lensAdapter->serialNumber());
        self::assertSame(35.0, $lensAdapter->focalLengthMm());
        self::assertSame([24.0, 70.0, 2.8, 2.8], $lensAdapter->specification());

        $exposureAdapter = $parsedExif->exposureMetadata();
        self::assertSame(200, $exposureAdapter->iso());
        self::assertSame(0.01, $exposureAdapter->exposureTime());
        self::assertSame(2.8, $exposureAdapter->aperture());
        self::assertSame($parsedExif->shutterSpeedSeconds(), $exposureAdapter->shutterSpeedSeconds());
        self::assertSame(-0.3333333333333333, $exposureAdapter->exposureBias());

        $deviceAdapter = $parsedExif->deviceMetadata();
        self::assertSame('CameraOS 1.0', $deviceAdapter->software());
        self::assertSame('RawLab 3.4', $deviceAdapter->rawDevelopingSoftware());
        self::assertSame('PixelEdit 2.1', $deviceAdapter->imageEditingSoftware());
        self::assertSame('MetaTool 5.0', $deviceAdapter->metadataEditingSoftware());

        $imageAdapter = $parsedExif->imageMetadata();
        self::assertSame(6000, $imageAdapter->width());
        self::assertSame(4000, $imageAdapter->height());
        self::assertSame(Orientation::RIGHT_TOP, $imageAdapter->orientation());
        self::assertSame(Compression::JPEG, $imageAdapter->compression());
        self::assertSame(ColorSpace::SRGB, $imageAdapter->colorSpace());

        $temporalAdapter = $parsedExif->temporalMetadata();
        self::assertInstanceOf(DateTimeImmutable::class, $temporalAdapter->captureDateTime());
        self::assertInstanceOf(DateTimeImmutable::class, $temporalAdapter->dateTimeOriginal());
        self::assertInstanceOf(DateTimeImmutable::class, $temporalAdapter->dateTimeDigitized());
        self::assertSame('+02:00', $temporalAdapter->offsetTimeOriginal());
        self::assertSame('+02:00', $temporalAdapter->offsetTimeDigitized());
        self::assertSame('+02:00', $temporalAdapter->offsetTime());

        $gpsAdapter = $parsedExif->gpsMetadata();
        self::assertSame(52.5, $gpsAdapter->latitude());
        self::assertSame(13.4, $gpsAdapter->longitude());
        self::assertSame(35.0, $gpsAdapter->altitude());
        self::assertInstanceOf(DateTimeImmutable::class, $gpsAdapter->timestamp());
        self::assertEquals($parsedExif->gps(), $gpsAdapter->all());
    }

    /**
     * Creates ParsedExif without a GPS IFD.
     * Verifies the GPS adapter exposes null scalar fields and the canonical empty map.
     *
     * @return void
     */
    #[Test]
    public function gpsAdapterReturnsNullCoordinatesWhenGpsIfdIsMissing(): void
    {
        $parsedExif = $this->parsedExifWithMetadata(includeGps: false);
        $gpsAdapter = $parsedExif->gpsMetadata();

        self::assertNull($gpsAdapter->latitude());
        self::assertNull($gpsAdapter->longitude());
        self::assertNull($gpsAdapter->altitude());
        self::assertNull($gpsAdapter->timestamp());
        self::assertEquals($parsedExif->gps(), $gpsAdapter->all());
    }

    private function parsedExifWithMetadata(bool $includeGps = true): ParsedExif
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE         => new IfdEntry(ExifTag::MAKE, 2, 1, 'MagicSunday'),
            ExifTag::MODEL        => new IfdEntry(ExifTag::MODEL, 2, 1, 'Photon X'),
            ExifTag::SOFTWARE     => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'CameraOS 1.0'),
            ExifTag::ORIENTATION  => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::RIGHT_TOP->value),
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 6000),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, 4, 1, 4000),
            ExifTag::DATETIME     => new IfdEntry(ExifTag::DATETIME, 2, 20, "2024:05:06 14:30:15\0"),
        ]);

        $exifIfd = new Ifd([
            ExifTag::BODY_SERIAL_NUMBER        => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, 'BODY-123'),
            ExifTag::CAMERA_FIRMWARE           => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'FW-2.1.0'),
            ExifTag::LENS_MAKE                 => new IfdEntry(ExifTag::LENS_MAKE, 2, 1, 'Optic Corp'),
            ExifTag::LENS_MODEL                => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, '24-70mm'),
            ExifTag::LENS_SERIAL_NUMBER        => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LENS-456'),
            ExifTag::FOCAL_LENGTH              => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [35, 1]),
            ExifTag::LENS_SPECIFICATION        => new IfdEntry(ExifTag::LENS_SPECIFICATION, 5, 4, [[24, 1], [70, 1], [28, 10], [28, 10]]),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY  => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 4, 1, 200),
            ExifTag::EXPOSURE_TIME             => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [1, 100]),
            ExifTag::F_NUMBER                  => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [28, 10]),
            ExifTag::SHUTTER_SPEED_VALUE       => new IfdEntry(ExifTag::SHUTTER_SPEED_VALUE, 10, 1, [6643856, 1000000]),
            ExifTag::EXPOSURE_BIAS_VALUE       => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, [-1, 3]),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'RawLab 3.4'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'PixelEdit 2.1'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'MetaTool 5.0'),
            ExifTag::PIXEL_X_DIMENSION         => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 4, 1, 6000),
            ExifTag::PIXEL_Y_DIMENSION         => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 4, 1, 4000),
            ExifTag::COLOR_SPACE               => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
            ExifTag::DATETIME_ORIGINAL         => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 20, "2024:05:06 14:30:15\0"),
            ExifTag::DATETIME_DIGITIZED        => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 20, "2024:05:06 14:30:15\0"),
            ExifTag::OFFSET_TIME_ORIGINAL      => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 7, '+02:00'),
            ExifTag::OFFSET_TIME_DIGITIZED     => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 7, '+02:00'),
            ExifTag::OFFSET_TIME               => new IfdEntry(ExifTag::OFFSET_TIME, 2, 7, '+02:00'),
        ]);

        $gpsIfd = null;

        if ($includeGps) {
            $gpsIfd = new Ifd([
                ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
                ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[52, 1], [30, 1], [0, 1]]),
                ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
                ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[13, 1], [24, 1], [0, 1]]),
                ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
                ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [35, 1]),
                ExifTag::GPS_DATE_STAMP    => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, '2024:05:06'),
                ExifTag::GPS_TIME_STAMP    => new IfdEntry(ExifTag::GPS_TIME_STAMP, 5, 3, [[12, 1], [34, 1], [56, 1]]),
            ]);
        }

        return new ParsedExif($ifd0, $exifIfd, $gpsIfd, null, null);
    }
}
