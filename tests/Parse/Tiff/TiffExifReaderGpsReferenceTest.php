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
 * GPS reference handling derived from EXIF 3.0 §4.6.6 (GPS tag examples)
 * and EXIF 2.32 §4.6.6 for backward-compatible hemisphere/altitude signs.
 */
#[CoversClass(TiffExifReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifReaderGpsReferenceTest extends TestCase
{
    #[Test]
    public function parsesSouthAndWestCoordinatesFromClassicTiff(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildGpsExample(Endian::Little));

        $gps = $result->gps();

        $expectedLatitude  = -1 * (12 + (34 / 60) + (5678 / 100 / 3600));
        $expectedLongitude = -1 * (98 + (45 / 60) + (4321 / 100 / 3600));

        self::assertSame('S', $gps['lat_ref']);
        self::assertSame('W', $gps['lon_ref']);
        self::assertEqualsWithDelta($expectedLatitude, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta($expectedLongitude, $gps['lon'], 0.000001);
        self::assertSame(1, $gps['alt_ref']);
        self::assertEqualsWithDelta(-5.5, $gps['alt'], 0.000001);
    }

    #[Test]
    public function parsesSouthAndWestCoordinatesFromBigTiff(): void
    {
        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($this->buildBigTiffGpsExample(Endian::Little));

        $gps = $result->gps();

        $expectedLatitude  = -1 * (12 + (34 / 60) + (5678 / 100 / 3600));
        $expectedLongitude = -1 * (98 + (45 / 60) + (4321 / 100 / 3600));

        self::assertSame('S', $gps['lat_ref']);
        self::assertSame('W', $gps['lon_ref']);
        self::assertEqualsWithDelta($expectedLatitude, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta($expectedLongitude, $gps['lon'], 0.000001);
        self::assertSame(1, $gps['alt_ref']);
        self::assertEqualsWithDelta(-5.5, $gps['alt'], 0.000001);
    }

    private function buildGpsExample(Endian $endian): string
    {
        $packShort    = $endian === Endian::Little ? 'v' : 'n';
        $packLong     = $endian === Endian::Little ? 'V' : 'N';
        $packRational = $endian === Endian::Little ? 'V2' : 'N2';

        $header           = $endian->value
            . pack($packShort, TiffConst::MAGIC_CLASSIC)
            . pack($packLong, 8);
        $ifd0Length       = 2 + 12 + 4;
        $gpsIfdOffset     = 8 + $ifd0Length;
        $gpsIfdLength     = 2 + (6 * 12) + 4;
        $gpsDataOffset    = $gpsIfdOffset + $gpsIfdLength;
        $gpsLongitudeData = $gpsDataOffset + (3 * 8);

        $ifd0 = pack($packShort, 1)
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $gpsIfdOffset)
            . pack($packLong, 0);

        $gpsIfd = pack($packShort, 6)
            // GPSLatitudeRef = "S"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('A4', 'S')
            // GPSLatitude = [12, 34, 56.78]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, $gpsDataOffset)
            // GPSLongitudeRef = "W"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('A4', 'W')
            // GPSLongitude = [98, 45, 43.21]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, $gpsLongitudeData)
            // GPSAltitudeRef = 1 (below sea level)
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . pack($packLong, 1)
            . pack($packLong, 1)
            // GPSAltitude = 11/2
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, $gpsLongitudeData + (3 * 8))
            . pack($packLong, 0);

        $gpsData = pack($packRational, 12, 1)
            . pack($packRational, 34, 1)
            . pack($packRational, 5678, 100)
            . pack($packRational, 98, 1)
            . pack($packRational, 45, 1)
            . pack($packRational, 4321, 100)
            . pack($packRational, 11, 2);

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }

    private function buildBigTiffGpsExample(Endian $endian): string
    {
        $packShort = $endian === Endian::Little ? 'v' : 'n';
        $packLong8 = $endian === Endian::Little ? 'P' : 'J';
        $packRatio = $endian === Endian::Little ? 'V2' : 'N2';

        $header = $endian->value
            . pack($packShort, TiffConst::MAGIC_BIG)
            . pack($packShort, 8)
            . pack($packShort, 0)
            . pack($packLong8, 16);

        $firstIfdOffset = 16;
        $ifd0EntryCount = pack($packLong8, 1);
        $gpsIfdOffset   = $firstIfdOffset + 8 + 20 + 8; // header + entry count + one entry + next offset

        $ifd0 = $ifd0EntryCount
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . pack($packLong8, 1)
            . pack($packLong8, $gpsIfdOffset)
            . pack($packLong8, 0);

        $gpsEntryCount = pack($packLong8, 6);
        $gpsDataOffset = $gpsIfdOffset + 8 + (6 * 20) + 8;

        $gpsIfd = $gpsEntryCount
            // GPSLatitudeRef = "S" (inline)
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong8, 2)
            . pack('A8', 'S')
            // GPSLatitude = [12, 34, 56.78]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong8, 3)
            . pack($packLong8, $gpsDataOffset)
            // GPSLongitudeRef = "W" (inline)
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong8, 2)
            . pack('A8', 'W')
            // GPSLongitude = [98, 45, 43.21]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong8, 3)
            . pack($packLong8, $gpsDataOffset + (3 * 8))
            // GPSAltitudeRef = 1
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . pack($packLong8, 1)
            . pack($packLong8, 1)
            // GPSAltitude = 11/2
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong8, 1)
            . pack($packRatio, 11, 2)
            . pack($packLong8, 0);

        $gpsData = pack($packRatio, 12, 1)
            . pack($packRatio, 34, 1)
            . pack($packRatio, 5678, 100)
            . pack($packRatio, 98, 1)
            . pack($packRatio, 45, 1)
            . pack($packRatio, 4321, 100)
            . pack($packRatio, 11, 2);

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }
}
