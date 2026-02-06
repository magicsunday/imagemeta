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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Verifies GPS reference handling for latitude, longitude, and altitude signs.
 * It parses sample GPS IFDs and checks that S/W and altitude reference flags affect sign.
 * The tests ensure classic TIFF layouts yield consistent GPS fields and numeric values.
 * This keeps GPS parsing aligned with EXIF reference tag semantics across versions.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifParserGpsReferenceTest extends TestCase
{
    /**
     * Parses a classic TIFF GPS example with S/W references and altitude below sea level.
     * Verifies the parser applies negative signs to latitude, longitude, and altitude.
     *
     * @return void
     */
    #[Test]
    public function parsesSouthAndWestCoordinatesFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
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

    /**
     * Parses a BigTIFF GPS example that uses S/W references and a negative altitude.
     * Confirms BigTIFF parsing applies the same sign handling as classic TIFF.
     *
     * @return void
     */
    #[Test]
    public function parsesSouthAndWestCoordinatesFromBigTiff(): void
    {
        $reader = new TiffExifParser();
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

        $header = $endian->value
            . pack($packShort, TiffConst::MAGIC_CLASSIC)
            . pack($packLong, 8);
        $gpsIfdOffset  = 26;
        $gpsDataOffset = $gpsIfdOffset + 2 + (6 * 12) + 4;

        $ifd0EntryCount = pack($packShort, 1);
        $ifd0NextOffset = pack($packLong, 0);

        $ifd0Length   = strlen($ifd0EntryCount) + 12 + strlen($ifd0NextOffset);
        $gpsIfdOffset = strlen($header) + $ifd0Length;

        $ifd0 = $ifd0EntryCount
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $gpsIfdOffset)
            . $ifd0NextOffset;

        $gpsEntryCount     = pack($packShort, 6);
        $gpsIfdPlaceholder = $gpsEntryCount
            . str_repeat("\0", 6 * 12)
            . $ifd0NextOffset;
        $gpsIfdLength  = strlen($gpsIfdPlaceholder);
        $gpsDataOffset = strlen($header . $ifd0) + $gpsIfdLength;

        $gpsIfd = $gpsEntryCount
            // GPSLatitudeRef = "S"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', 'S')
            // GPSLatitude = [12, 34, 56.78]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, $gpsDataOffset)
            // GPSLongitudeRef = "W"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', 'W')
            // GPSLongitude = [98, 45, 43.21]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, $gpsDataOffset + (3 * 8))
            // GPSAltitudeRef = 1 (below sea level)
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . pack($packLong, 1)
            . pack($packLong, 1)
            // GPSAltitude = 11/2
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, $gpsDataOffset + (6 * 8))
            . $ifd0NextOffset;

        $gpsData = pack($packRational, 12, 1)
            . pack($packRational, 34, 1)
            . pack($packRational, 5678, 100)
            . pack($packRational, 98, 1)
            . pack($packRational, 45, 1)
            . pack($packRational, 4321, 100)
            . pack($packRational, 11, 2);

        return $header . $ifd0 . $gpsIfd . $gpsData . "\0\0\0\0";
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

        $ifd0EntryCount = pack($packLong8, 1);
        $ifd0NextOffset = pack($packLong8, 0);

        $ifd0Length   = strlen($ifd0EntryCount) + 20 + strlen($ifd0NextOffset);
        $gpsIfdOffset = 16 + $ifd0Length;

        $ifd0 = $ifd0EntryCount
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . pack($packLong8, 1)
            . pack($packLong8, $gpsIfdOffset)
            . $ifd0NextOffset;

        $gpsEntryCount     = pack($packLong8, 6);
        $gpsIfdPlaceholder = $gpsEntryCount
            . str_repeat("\0", 6 * 20)
            . $ifd0NextOffset;
        $gpsIfdLength  = strlen($gpsIfdPlaceholder);
        $gpsDataOffset = strlen($header . $ifd0) + $gpsIfdLength;
        $lonOffset     = $gpsDataOffset + (3 * 8);

        $gpsIfd = $gpsEntryCount
            // GPSLatitudeRef = "S" (inline)
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong8, 2)
            . pack('a8', 'S')
            // GPSLatitude = [12, 34, 56.78]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong8, 3)
            . pack($packLong8, $gpsDataOffset)
            // GPSLongitudeRef = "W" (inline)
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong8, 2)
            . pack('a8', 'W')
            // GPSLongitude = [98, 45, 43.21]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong8, 3)
            . pack($packLong8, $lonOffset)
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
            . $ifd0NextOffset;

        $gpsData = pack($packRatio, 12, 1)
            . pack($packRatio, 34, 1)
            . pack($packRatio, 5678, 100)
            . pack($packRatio, 98, 1)
            . pack($packRatio, 45, 1)
            . pack($packRatio, 4321, 100);

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }
}
