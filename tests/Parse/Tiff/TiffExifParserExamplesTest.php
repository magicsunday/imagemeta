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
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormaliser;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Parses the EXIF 3.0 sample TIFF layouts across endian variants.
 * It validates that common EXIF, GPS, and TIFF tags are decoded as documented examples.
 * The suite covers both classic TIFF and BigTIFF layouts with representative IFD chains.
 * This ensures the parser matches the published reference structures from the spec.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormaliser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserExamplesTest extends TestCase
{
    /**
     * Parses the EXIF 3.0 classic TIFF example encoded in little-endian order.
     * Confirms common EXIF values and GPS fields are extracted correctly.
     */
    #[Test]
    public function parsesClassicLittleEndianExample(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildClassicExample(Endian::Little));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    /**
     * Parses the EXIF 3.0 classic TIFF example encoded in big-endian order.
     * Verifies the parser handles big-endian offsets and returns expected EXIF/GPS data.
     */
    #[Test]
    public function parsesClassicBigEndianExample(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildClassicExample(Endian::Big));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    /**
     * Parses a BigTIFF example encoded in little-endian order with 64-bit offsets.
     * Ensures common EXIF and GPS values match the expected sample values.
     */
    #[Test]
    public function parsesBigTiffLittleEndianExample(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildBigTiffExample(Endian::Little));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    /**
     * Parses a BigTIFF example encoded in big-endian order.
     * Confirms the parser supports BigTIFF byte order and extracts expected fields.
     */
    #[Test]
    public function parsesBigTiffBigEndianExample(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildBigTiffExample(Endian::Big));

        $this->assertCommonExifValues($result);
        $this->assertGpsValues($result);
    }

    /**
     * Builds a classic TIFF sample that includes Interop IFD and thumbnail data.
     * Verifies interop index, thumbnail JPEG info, and tile metadata are parsed.
     */
    #[Test]
    public function parsesClassicInteropAndThumbnailExample(): void
    {
        $reader  = new TiffExifParser();
        $payload = $this->buildClassicExampleWithInteropAndThumbnail(Endian::Little);
        $result  = $reader->parseFromBlob($payload['blob']);

        self::assertSame('R98', $result->interopIndex());
        self::assertTrue($result->hasThumbnail());
        self::assertSame($payload['jpegOffset'], $result->thumbnailJpegInterchangeFormat());
        self::assertSame($payload['jpegLength'], $result->thumbnailJpegInterchangeFormatLength());
        self::assertSame(Compression::Jpeg, $result->thumbnailCompression());
        self::assertSame(160, $result->thumbnailTileWidth());
        self::assertSame(120, $result->thumbnailTileLength());
        self::assertSame($payload['tileOffsets'], $result->thumbnailTileOffsets());
        self::assertSame($payload['tileByteCounts'], $result->thumbnailTileByteCounts());
    }

    private function assertCommonExifValues(ParsedExif $result): void
    {
        self::assertEqualsWithDelta(1 / 60, $result->exposureTime(), 0.000001);
        self::assertEqualsWithDelta(2.8, $result->fNumber(), 0.000001);
        self::assertEqualsWithDelta(50.0, $result->focalLengthMm(), 0.000001);
        self::assertEqualsWithDelta(6.5, $result->flashEnergy(), 0.000001);
        self::assertSame(200, $result->iso());
        self::assertNull($result->dateTimeOriginal());
        self::assertSame('2024:01:02 03:04:05', $result->dateTimeOriginalRaw());
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

        // IFD0 with ImageWidth, ImageLength, and pointers to ExifIFD and GPSIFD.
        $ifd0 = pack($packShort, 4)
            // ImageWidth SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_WIDTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 100) . pack($packShort, 0)
            // ImageLength SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_LENGTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 100) . pack($packShort, 0)
            . pack($packShort, ExifTag::EXIF_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 62)
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 252)
            . pack($packLong, 0);

        // ExifIFD entries (9 entries => data region starts at offset 176).
        $exifIfd = pack($packShort, 9)
            // ExposureTime = 1/60
            . pack($packShort, ExifTag::EXPOSURE_TIME)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 176)
            // FNumber = 2.8 (28/10)
            . pack($packShort, ExifTag::F_NUMBER)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 184)
            // PhotographicSensitivity = 200
            . pack($packShort, ExifTag::PHOTOGRAPHIC_SENSITIVITY)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 200) . pack($packShort, 0)
            // SensitivityType = 3 (SOS + REI + ISO speed)
            . pack($packShort, ExifTag::SENSITIVITY_TYPE)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 3) . pack($packShort, 0)
            // ISOSpeed = 200
            . pack($packShort, ExifTag::ISO_SPEED)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 200)
            // DateTimeOriginal = "2024:01:02 03:04:05\0"
            . pack($packShort, ExifTag::DATETIME_ORIGINAL)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 20)
            . pack($packLong, 192)
            // FocalLength = 50 mm
            . pack($packShort, ExifTag::FOCAL_LENGTH)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 212)
            // UserComment with ASCII prefix
            . pack($packShort, ExifTag::USER_COMMENT)
            . pack($packShort, TiffConst::TYPE_UNDEFINED)
            . pack($packLong, 24)
            . pack($packLong, 220)
            // FlashEnergy = 6.5 BCPS (65/10)
            . pack($packShort, ExifTag::FLASH_ENERGY)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 244)
            . pack($packLong, 0);

        $exifData = pack($packRational, 1, 60)
            . pack($packRational, 28, 10)
            . '2024:01:02 03:04:05' . "\0"
            . pack($packRational, 50, 1)
            . 'ASCII' . "\0\0\0" . 'Sample EXIF 3.0' . "\0"
            . pack($packRational, 65, 10);

        // GPS IFD entries (6 entries => data region starts at offset 330).
        $gpsIfd = pack($packShort, 6)
            // GPSLatitudeRef = "N"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', 'N')
            // GPSLatitude = [35, 59, 30.3]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, 330)
            // GPSLongitudeRef = "E"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', 'E')
            // GPSLongitude = [139, 44, 30]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, 354)
            // GPSAltitudeRef = 0 (above sea level)
            . pack($packShort, ExifTag::GPS_ALTITUDE_REF)
            . pack($packShort, TiffConst::TYPE_BYTE)
            . pack($packLong, 1)
            . pack($packLong, 0)
            // GPSAltitude = 550/1
            . pack($packShort, ExifTag::GPS_ALTITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 1)
            . pack($packLong, 378)
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

    /**
     * Builds a classic TIFF blob with Exif/Interop IFD chaining and a thumbnail IFD1.
     * This checks the behavior for the specific inputs used in the test.
     *
     * EXIF 3.0 §4.5.2 describes the classic TIFF directory layout, §4.6.3.3.1
     * specifies the Interoperability IFD pointer field, and §4.6.5.2.4 plus
     * §4.6.5.1.6 (Table 3) document JPEG thumbnail tags in IFD1.
     *
     * @return array{blob: string, jpegOffset: int, jpegLength: int, tileOffsets: list<int>, tileByteCounts: list<int>}
     */
    private function buildClassicExampleWithInteropAndThumbnail(Endian $endian): array
    {
        $packShort = $endian === Endian::Little ? 'v' : 'n';
        $packLong  = $endian === Endian::Little ? 'V' : 'N';

        $ifd0EntryCount       = 3;
        $exifIfdEntryCount    = 1;
        $interopIfdEntryCount = 1;
        $ifd1EntryCount       = 8;

        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4;
        $exifIfdSize    = 2 + (12 * $exifIfdEntryCount) + 4;
        $interopIfdSize = 2 + (12 * $interopIfdEntryCount) + 4;
        $ifd1Size       = 2 + (12 * $ifd1EntryCount) + 4;

        $header = $endian->value
            . pack($packShort, TiffConst::MAGIC_CLASSIC)
            . pack($packLong, 8);
        $exifIfdOffset = 8 + $ifd0Size;
        $interopOffset = $exifIfdOffset + $exifIfdSize;
        $ifd1Offset    = $interopOffset + $interopIfdSize;
        $ifd1DataStart = $ifd1Offset + $ifd1Size;

        $tileOffsets      = [$ifd1DataStart + 16, $ifd1DataStart + 20];
        $tileByteCounts   = [4, 6];
        $tileOffsetsStart = $ifd1DataStart;
        $tileCountsStart  = $tileOffsetsStart + 8;
        $jpegOffset       = $tileCountsStart + 8;
        $jpegLength       = 12;

        $ifd0 = pack($packShort, $ifd0EntryCount)
            // ImageWidth SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_WIDTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 100) . pack($packShort, 0)
            // ImageLength SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_LENGTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 100) . pack($packShort, 0)
            . pack($packShort, ExifTag::EXIF_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $exifIfdOffset)
            . pack($packLong, $ifd1Offset);

        $exifIfd = pack($packShort, $exifIfdEntryCount)
            // EXIF 3.0 §4.6.3.3.1 requires a single LONG offset to the Interoperability IFD.
            . pack($packShort, ExifTag::INTEROPERABILITY_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $interopOffset)
            . pack($packLong, 0);

        $interopIfd = pack($packShort, $interopIfdEntryCount)
            . pack($packShort, ExifTag::INTEROPERABILITY_INDEX)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 4)
            . pack('a4', 'R98')
            . pack($packLong, 0);

        $ifd1 = pack($packShort, $ifd1EntryCount)
            // EXIF 3.0 §4.6.5.1.4 defines Compression=6 for JPEG thumbnails.
            . pack($packShort, ExifTag::COMPRESSION)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, Compression::Jpeg->value)
            . pack($packShort, 0)
            . pack($packShort, TiffTag::TILE_WIDTH)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 160)
            . pack($packShort, TiffTag::TILE_LENGTH)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, 120)
            . pack($packShort, TiffTag::TILE_OFFSETS)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 2)
            . pack($packLong, $tileOffsetsStart)
            . pack($packShort, TiffTag::TILE_BYTE_COUNTS)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 2)
            . pack($packLong, $tileCountsStart)
            // TIFF 6.0 Section 22: JPEGProc is mandatory for Compression=6.
            . pack($packShort, TiffTag::JPEG_PROC)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong, 1)
            . pack($packShort, 1)
            . pack($packShort, 0)
            // TIFF 6.0 §2 requires ascending tag order within each IFD.
            // EXIF 3.0 §4.6.5.2.4 (JPEGInterchangeFormat) and §4.6.5.1.6 (Table 3).
            . pack($packShort, ExifTag::JPEG_INTERCHANGE_FORMAT)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $jpegOffset)
            . pack($packShort, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH)
            . pack($packShort, TiffConst::TYPE_LONG)
            . pack($packLong, 1)
            . pack($packLong, $jpegLength)
            . pack($packLong, 0);

        $ifd1Data = pack($packLong, $tileOffsets[0])
            . pack($packLong, $tileOffsets[1])
            . pack($packLong, $tileByteCounts[0])
            . pack($packLong, $tileByteCounts[1]);

        $jpegData = "\xFF\xD8\x00\x11\x22\x33\x44\x55\x66\x77\xFF\xD9";

        return [
            'blob'           => $header . $ifd0 . $exifIfd . $interopIfd . $ifd1 . $ifd1Data . $jpegData,
            'jpegOffset'     => $jpegOffset,
            'jpegLength'     => $jpegLength,
            'tileOffsets'    => $tileOffsets,
            'tileByteCounts' => $tileByteCounts,
        ];
    }

    private function buildBigTiffExample(Endian $endian): string
    {
        $packShort    = $endian === Endian::Little ? 'v' : 'n';
        $packRational = $endian === Endian::Little ? 'V2' : 'N2';
        $header       = $endian->value
            . pack($packShort, TiffConst::MAGIC_BIG)
            . pack($packShort, 8)
            . pack($packShort, 0)
            . $this->packUint64(16, $endian);

        // IFD0 with 64-bit entry count and offsets.
        $ifd0 = $this->packUint64(4, $endian)
            // ImageWidth SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_WIDTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . $this->packUint64(1, $endian)
            . pack($packShort, 100) . pack('a6', '')
            // ImageLength SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_LENGTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . $this->packUint64(1, $endian)
            . pack($packShort, 100) . pack('a6', '')
            . pack($packShort, ExifTag::EXIF_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . $this->packUint64(1, $endian)
            . $this->packUint64(112, $endian)
            . pack($packShort, ExifTag::GPS_IFD_POINTER)
            . pack($packShort, TiffConst::TYPE_IFD8)
            . $this->packUint64(1, $endian)
            . $this->packUint64(352, $endian)
            . $this->packUint64(0, $endian);

        // ExifIFD entries (9 entries => data region starts at offset 308).
        $exifIfd = $this->packUint64(9, $endian)
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
            // PhotographicSensitivity = 200
            . pack($packShort, ExifTag::PHOTOGRAPHIC_SENSITIVITY)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . $this->packUint64(1, $endian)
            . pack($packShort, 200) . pack('a6', '')
            // SensitivityType = 3 (SOS + REI + ISO speed)
            . pack($packShort, ExifTag::SENSITIVITY_TYPE)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . $this->packUint64(1, $endian)
            . pack($packShort, 3) . pack('a6', '')
            // ISOSpeed = 200
            . pack($packShort, ExifTag::ISO_SPEED)
            . pack($packShort, TiffConst::TYPE_LONG)
            . $this->packUint64(1, $endian)
            . ($endian === Endian::Little ? pack('V2', 200, 0) : pack('N2', 200, 0))
            // DateTimeOriginal
            . pack($packShort, ExifTag::DATETIME_ORIGINAL)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(20, $endian)
            . $this->packUint64(308, $endian)
            // FocalLength = 50 mm
            . pack($packShort, ExifTag::FOCAL_LENGTH)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(1, $endian)
            . pack($packRational, 50, 1)
            // UserComment with ASCII prefix
            . pack($packShort, ExifTag::USER_COMMENT)
            . pack($packShort, TiffConst::TYPE_UNDEFINED)
            . $this->packUint64(24, $endian)
            . $this->packUint64(328, $endian)
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

        // GPS IFD entries (6 entries => data region starts at offset 488).
        $gpsIfd = $this->packUint64(6, $endian)
            // GPSLatitudeRef = "N"
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(2, $endian)
            . pack('a8', 'N')
            // GPSLatitude = [35, 59, 30.3]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(3, $endian)
            . $this->packUint64(488, $endian)
            // GPSLongitudeRef = "E"
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . $this->packUint64(2, $endian)
            . pack('a8', 'E')
            // GPSLongitude = [139, 44, 30]
            . pack($packShort, ExifTag::GPS_LONGITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . $this->packUint64(3, $endian)
            . $this->packUint64(512, $endian)
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
