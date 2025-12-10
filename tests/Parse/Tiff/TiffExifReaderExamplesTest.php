<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Positive parsing examples derived from the EXIF 3.0 sample TIFF layouts.
 *
 * EXIF 3.0 §4.5.2 illustrates representative IFD0/ExifIFD/GPSIFD chains for
 * classic TIFF (0x002A) and BigTIFF (0x002B) in both little- and big-endian
 * order. The legacy layout is retained from EXIF 2.32 §4.5.2 with identical
 * tag groupings for these fields.
 */
#[CoversClass(TiffExifReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifReaderExamplesTest extends TestCase
{
    #[Test]
    public function parsesClassicLittleEndianExample(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildClassicExample(Endian::Little));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    #[Test]
    public function parsesClassicBigEndianExample(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildClassicExample(Endian::Big));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    #[Test]
    public function parsesBigTiffLittleEndianExample(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildBigTiffExample(Endian::Little));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    #[Test]
    public function parsesBigTiffBigEndianExample(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildBigTiffExample(Endian::Big));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    private function assertCommonExifValues(ParsedExif $result): void
    {
        self::assertEqualsWithDelta(1 / 60, $result->exposureTime(), 0.000001);
        self::assertEqualsWithDelta(2.8, $result->fNumber(), 0.000001);
        self::assertEqualsWithDelta(50.0, $result->focalLengthMm(), 0.000001);
        self::assertEqualsWithDelta(6.5, $result->flashEnergy(), 0.000001);
        self::assertSame(200, $result->iso());
        self::assertSame('2024:01:02 03:04:05', $result->dateTimeOriginal()?->format('Y:m:d H:i:s'));
        self::assertSame('Sample EXIF 3.0', $result->userComment());
    }

    private function assertGpsValues(ParsedExif $result): void
    {
        $gps = $result->gps();

        $expectedLatitude  = 35 + (59 / 60) + (30.3 / 3600);
        $expectedLongitude = 139 + (44 / 60) + (30.0 / 3600);

        self::assertSame('N', $gps['lat_ref']);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta($expectedLatitude, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta($expectedLongitude, $gps['lon'], 0.000001);
        self::assertSame(0, $gps['alt_ref']);
        self::assertEqualsWithDelta(550.0, $gps['alt'], 0.000001);
    }

    private function buildClassicExample(Endian $endian): string
    {
        $packShort    = $endian === Endian::Little ? 'v' : 'n';
        $packLong     = $endian === Endian::Little ? 'V' : 'N';
        $packRational = $endian === Endian::Little ? 'V2' : 'N2';

        $header = $endian->value
            . pack($packShort, TiffConst::MAGIC_CLASSIC)
            . pack($packLong, 8);

        // IFD0 with pointers to ExifIFD and GPSIFD.
        $ifd0 = pack($packShort, 2)
            . pack($packShort, ExifTag::EXIF_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 38)
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 204)
            . pack($packLong, 0);

        // ExifIFD entries (7 entries => data region starts at offset 128).
        $exifIfd = pack($packShort, 7)
            // ExposureTime = 1/60
            . pack($packShort, ExifTag::EXPOSURE_TIME)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 128)
            // FNumber = 2.8 (28/10)
            . pack($packShort, ExifTag::F_NUMBER)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 136)
            // ISOSpeed = 200
            . pack($packShort, ExifTag::ISO_SPEED)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 200)
            // DateTimeOriginal = "2024:01:02 03:04:05\0"
            . pack($packShort, ExifTag::DATETIME_ORIGINAL)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 20)
            . pack($packLong, 144)
            // FocalLength = 50 mm
            . pack($packShort, ExifTag::FOCAL_LENGTH)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 164)
            // UserComment with ASCII prefix
            . pack($packShort, ExifTag::USER_COMMENT)
            . pack($packShort, TiffConst::TYPE_UNDEFINED)
            . pack($packLong, 24)
            . pack($packLong, 172)
            // FlashEnergy = 6.5 BCPS (65/10)
            . pack($packShort, ExifTag::FLASH_ENERGY)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 196)
            . pack($packLong, 0);

        $exifData = pack($packRational, 1, 60)
            . pack($packRational, 28, 10)
            . '2024:01:02 03:04:05' . "\0"
            . pack($packRational, 50, 1)
            . 'ASCII' . "\0\0\0" . 'Sample EXIF 3.0' . "\0"
            . pack($packRational, 65, 10);

        // GPS IFD entries (6 entries => data region starts at offset 282).
        $gpsIfd = pack($packShort, 6)
            // GPSLatitudeRef = "N"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('A4', 'N')
            // GPSLatitude = [35, 59, 30.3]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, 282)
            // GPSLongitudeRef = "E"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('A4', 'E')
            // GPSLongitude = [139, 44, 30]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, 306)
            // GPSAltitudeRef = 0 (above sea level)
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . pack($packLong, 1)
            . pack($packLong, 0)
            // GPSAltitude = 550/1
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 330)
            . pack($packLong, 0);

        $gpsData = pack($packRational, 35, 1)
            . pack($packRational, 59, 1)
            . pack($packRational, 303, 10)
            . pack($packRational, 139, 1)
            . pack($packRational, 44, 1)
            . pack($packRational, 30, 1)
            . pack($packRational, 550, 1);

        return $header . $ifd0 . $exifIfd . $exifData . $gpsIfd . $gpsData;
    }

    private function buildBigTiffExample(Endian $endian): string
    {
        $packShort    = $endian === Endian::Little ? 'v' : 'n';
        $packLong     = $endian === Endian::Little ? 'V' : 'N';
        $packRational = $endian === Endian::Little ? 'V2' : 'N2';
        $header       = $endian->value
            . pack($packShort, TiffConst::MAGIC_BIG)
            . pack($packShort, 8)
            . pack($packShort, 0)
            . $this->packUint64(16, $endian);

        // IFD0 with 64-bit entry count and offsets.
        $ifd0 = $this->packUint64(2, $endian)
            . pack($packShort, ExifTag::EXIF_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . $this->packUint64(1, $endian)
            . $this->packUint64(72, $endian)
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . $this->packUint64(1, $endian)
            . $this->packUint64(272, $endian)
            . $this->packUint64(0, $endian);

        // ExifIFD entries (7 entries => data region starts at offset 228).
        $exifIfd = $this->packUint64(7, $endian)
            // ExposureTime = 1/60
            . pack($packShort, ExifTag::EXPOSURE_TIME)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 1, 60)
            // FNumber = 2.8 (28/10)
            . pack($packShort, ExifTag::F_NUMBER)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 28, 10)
            // ISOSpeed = 200
            . pack($packShort, ExifTag::ISO_SPEED)
            . pack($packShort, TiffConst::TYPE_LONG)
            . $this->packUint64(1, $endian)
            . ($endian === Endian::Little ? pack('V2', 200, 0) : pack('N2', 200, 0))
            // DateTimeOriginal
            . pack($packShort, ExifTag::DATETIME_ORIGINAL)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(20, $endian)
            . $this->packUint64(228, $endian)
            // FocalLength = 50 mm
            . pack($packShort, ExifTag::FOCAL_LENGTH)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 50, 1)
            // UserComment with ASCII prefix
            . pack($packShort, ExifTag::USER_COMMENT)
            . pack($packShort, TiffConst::TYPE_UNDEFINED)
            . $this->packUint64(24, $endian)
            . $this->packUint64(248, $endian)
            // FlashEnergy = 6.5 BCPS (65/10)
            . pack($packShort, ExifTag::FLASH_ENERGY)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 65, 10)
            . $this->packUint64(0, $endian);

        $exifData = '2024:01:02 03:04:05'
            . "\0"
            . 'ASCII'
            . "\0\0\0"
            . 'Sample EXIF 3.0'
            . "\0";

        // GPS IFD entries (6 entries => data region starts at offset 408).
        $gpsIfd = $this->packUint64(6, $endian)
            // GPSLatitudeRef = "N"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(2, $endian)
            . pack('A8', 'N')
            // GPSLatitude = [35, 59, 30.3]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(3, $endian)
            . $this->packUint64(408, $endian)
            // GPSLongitudeRef = "E"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(2, $endian)
            . pack('A8', 'E')
            // GPSLongitude = [139, 44, 30]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(3, $endian)
            . $this->packUint64(432, $endian)
            // GPSAltitudeRef = 0 (above sea level)
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . $this->packUint64(1, $endian)
            . $this->packUint64(0, $endian)
            // GPSAltitude = 550/1
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 550, 1)
            . $this->packUint64(0, $endian);

        $gpsData = pack($packRational, 35, 1)
            . pack($packRational, 59, 1)
            . pack($packRational, 303, 10)
            . pack($packRational, 139, 1)
            . pack($packRational, 44, 1)
            . pack($packRational, 30, 1);

        return $header . $ifd0 . $exifIfd . $exifData . $gpsIfd . $gpsData;
    }

    private function packUint64(int $value, Endian $endian): string
    {
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low  = $value & 0xFFFFFFFF;

        return $endian === Endian::Little
            ? pack('V2', $low, $high)
            : pack('NN', $high, $low);
    }
}
