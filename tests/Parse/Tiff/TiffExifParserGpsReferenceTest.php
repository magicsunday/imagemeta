<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
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

        $this->assertSouthWestReferenceCoordinates($result->gps());
    }

    /**
     * Parses valid edge coordinates (90/180 degrees) from a classic TIFF GPS IFD.
     *
     * @return void
     */
    #[Test]
    public function parsesCoordinateEdgeValuesFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildGpsExample(Endian::Little, 90, 180, 'N', 'E', true));

        $gps = $result->gps();

        self::assertSame('N', $gps['lat_ref']);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta(90.0, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(180.0, $gps['lon'], 0.000001);
    }

    /**
     * Accepts GPS reference tags encoded as ASCII[2] in the GPS IFD.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsReferenceTags')]
    public function acceptsGpsReferenceTagsWithAsciiCountTwo(int $tag, string $value, string $resultKey, int $valueTag): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithGpsRefAndValue($tag, $value, $valueTag),
        );

        self::assertSame($value, $result->gps()[$resultKey]);
    }

    /**
     * Rejects GPS reference tags encoded with non-ASCII TIFF type.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsReferenceTags')]
    public function rejectsGpsReferenceTagsWithWrongType(int $tag, string $value, string $_resultKey, int $_valueTag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1317);
        $this->expectExceptionMessage('must use TIFF type ASCII');

        (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithSingleGpsEntry($tag, TiffConst::TYPE_BYTE, 2, $value . "\0"),
        );
    }

    /**
     * Rejects GPS reference tags encoded with wrong count.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideGpsReferenceTags')]
    public function rejectsGpsReferenceTagsWithWrongCount(int $tag, string $value, string $_resultKey, int $_valueTag): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1318);
        $this->expectExceptionMessage('must contain exactly 2 bytes');

        (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithSingleGpsEntry($tag, TiffConst::TYPE_ASCII, 3, $value . "\0\0"),
        );
    }

    /**
     * @return iterable<string, array{0:int,1:string,2:string,3:int}>
     */
    public static function provideGpsReferenceTags(): iterable
    {
        yield 'GPSLatitudeRef N' => [ExifTag::GPS_LATITUDE_REF, 'N', 'lat_ref', ExifTag::GPS_LATITUDE];
        yield 'GPSLongitudeRef W' => [ExifTag::GPS_LONGITUDE_REF, 'W', 'lon_ref', ExifTag::GPS_LONGITUDE];
        yield 'GPSDestLatitudeRef S' => [ExifTag::GPS_DEST_LATITUDE_REF, 'S', 'dest_lat_ref', ExifTag::GPS_DEST_LATITUDE];
        yield 'GPSDestLongitudeRef E' => [ExifTag::GPS_DEST_LONGITUDE_REF, 'E', 'dest_lon_ref', ExifTag::GPS_DEST_LONGITUDE];
    }

    /**
     * Rejects latitude values above +90 degrees from a classic TIFF GPS IFD.
     *
     * @return void
     */
    #[Test]
    public function rejectsLatitudeAboveNinetyFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildGpsExample(Endian::Little, 91, 180, 'N', 'E', true));

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1463);
        $this->expectExceptionMessage('outside the valid latitude range');

        $result->gps();
    }

    /**
     * Rejects longitude values above +180 degrees from a classic TIFF GPS IFD.
     *
     * @return void
     */
    #[Test]
    public function rejectsLongitudeAboveOneHundredEightyFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($this->buildGpsExample(Endian::Little, 90, 181, 'N', 'E', true));

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1464);
        $this->expectExceptionMessage('outside the valid longitude range');

        $result->gps();
    }

    /**
     * Parses valid GPS date/time values and keeps fractional seconds in UTC output.
     *
     * @return void
     */
    #[Test]
    public function parsesValidGpsUtcDateTimeFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob(
            $this->buildGpsDateTimeExample("2025:03:01\0", [[12, 1], [34, 1], [251, 10]]),
        );
        $gpsTimestamp = $result->gpsTimestamp();

        self::assertSame('2025-03-01', $result->gpsDateStamp());
        self::assertSame('12:34:25.1', $result->gpsTimeStampString());
        self::assertInstanceOf(DateTimeImmutable::class, $gpsTimestamp);
        self::assertSame('2025-03-01T12:34:25+00:00', $gpsTimestamp->format('Y-m-d\TH:i:sP'));
        self::assertSame('100000', $gpsTimestamp->format('u'));
    }

    /**
     * Rejects invalid GPSDateStamp calendar values from classic TIFF payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsDateStampFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob(
            $this->buildGpsDateTimeExample("2025:02:30\0", [[12, 1], [34, 1], [56, 1]]),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1465);
        $this->expectExceptionMessage('GPSDateStamp');

        $result->gps();
    }

    /**
     * Rejects out-of-range GPSTimeStamp values from classic TIFF payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectsOutOfRangeGpsTimeStampFromClassicTiff(): void
    {
        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob(
            $this->buildGpsDateTimeExample("2025:03:01\0", [[25, 1], [0, 1], [0, 1]]),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1466);
        $this->expectExceptionMessage('GPSTimeStamp');

        $result->gps();
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

        $this->assertSouthWestReferenceCoordinates($result->gps());
    }

    /**
     * Asserts signed S/W coordinate and altitude semantics for the synthetic GPS fixture.
     *
     * @param array<string, mixed> $gps
     */
    private function assertSouthWestReferenceCoordinates(array $gps): void
    {
        $expectedLatitude  = -1 * (12 + (34 / 60) + (5678 / 100 / 3600));
        $expectedLongitude = -1 * (98 + (45 / 60) + (4321 / 100 / 3600));

        self::assertSame('S', $gps['lat_ref']);
        self::assertSame('W', $gps['lon_ref']);
        self::assertEqualsWithDelta($expectedLatitude, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta($expectedLongitude, $gps['lon'], 0.000001);
        self::assertSame(1, $gps['alt_ref']);
        self::assertEqualsWithDelta(-5.5, $gps['alt'], 0.000001);
    }

    private function buildGpsExample(
        Endian $endian,
        int $latitudeDegrees = 12,
        int $longitudeDegrees = 98,
        string $latitudeRef = 'S',
        string $longitudeRef = 'W',
        bool $zeroMinutesSeconds = false,
    ): string {
        $packShort    = $endian === Endian::Little ? 'v' : 'n';
        $packLong     = $endian === Endian::Little ? 'V' : 'N';
        $packRational = $endian === Endian::Little ? 'V2' : 'N2';

        $latitudeMinutes   = $zeroMinutesSeconds ? 0 : 34;
        $latitudeSecondsN  = $zeroMinutesSeconds ? 0 : 5678;
        $latitudeSecondsD  = $zeroMinutesSeconds ? 1 : 100;
        $longitudeMinutes  = $zeroMinutesSeconds ? 0 : 45;
        $longitudeSecondsN = $zeroMinutesSeconds ? 0 : 4321;
        $longitudeSecondsD = $zeroMinutesSeconds ? 1 : 100;

        $header = $endian->value
            . pack($packShort, TiffConst::MAGIC_CLASSIC)
            . pack($packLong, 8);

        $ifd0EntryCount = pack($packShort, 3);
        $ifd0NextOffset = pack($packLong, 0);

        $ifd0Length   = strlen($ifd0EntryCount) + (3 * 12) + strlen($ifd0NextOffset);
        $gpsIfdOffset = strlen($header) + $ifd0Length;

        $ifd0 = $ifd0EntryCount
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
            // GPS IFD pointer
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
            // GPSLatitudeRef
            . pack($packShort, ExifTag::GPS_LATITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', $latitudeRef)
            // GPSLatitude = [deg, minutes, seconds]
            . pack($packShort, ExifTag::GPS_LATITUDE)
            . pack($packShort, TiffConst::TYPE_RATIONAL)
            . pack($packLong, 3)
            . pack($packLong, $gpsDataOffset)
            // GPSLongitudeRef
            . pack($packShort, ExifTag::GPS_LONGITUDE_REF)
            . pack($packShort, TiffConst::TYPE_ASCII)
            . pack($packLong, 2)
            . pack('a4', $longitudeRef)
            // GPSLongitude = [deg, minutes, seconds]
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

        $gpsData = pack($packRational, $latitudeDegrees, 1)
            . pack($packRational, $latitudeMinutes, 1)
            . pack($packRational, $latitudeSecondsN, $latitudeSecondsD)
            . pack($packRational, $longitudeDegrees, 1)
            . pack($packRational, $longitudeMinutes, 1)
            . pack($packRational, $longitudeSecondsN, $longitudeSecondsD)
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

        $ifd0EntryCount = pack($packLong8, 3);
        $ifd0NextOffset = pack($packLong8, 0);

        $ifd0Length   = strlen($ifd0EntryCount) + (3 * 20) + strlen($ifd0NextOffset);
        $gpsIfdOffset = 16 + $ifd0Length;

        $ifd0 = $ifd0EntryCount
            // ImageWidth SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_WIDTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong8, 1)
            . pack($packShort, 100) . pack($packShort, 0) . pack('V', 0)
            // ImageLength SHORT[1] = 100
            . pack($packShort, ExifTag::IMAGE_LENGTH)
            . pack($packShort, TiffConst::TYPE_SHORT)
            . pack($packLong8, 1)
            . pack($packShort, 100) . pack($packShort, 0) . pack('V', 0)
            // GPS IFD pointer
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

    private function classicIfd0WithGpsPointerLength(): int
    {
        return 2 + (3 * 12) + 4;
    }

    private function buildClassicIfd0WithGpsPointer(int $gpsIfdOffset): string
    {
        return pack('v', 3)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::GPS_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $gpsIfdOffset)
            . pack('V', 0);
    }

    /**
     * Builds a classic TIFF with a GPS ref entry and matching coordinate value entry.
     */
    private function buildClassicTiffWithGpsRefAndValue(int $refTag, string $refValue, int $valueTag): string
    {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        // GPS IFD: count(2) + 2×entry(12) + nextIfd(4)
        $gpsIfdLength = 2 + (2 * 12) + 4;
        $dataOffset   = strlen($header . $ifd0) + $gpsIfdLength;

        $gpsIfd = pack('v', 2)
            // Ref entry (ASCII[2], inline)
            . pack('v', $refTag)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', $refValue)
            // Value entry (RATIONAL[3], external)
            . pack('v', $valueTag)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $dataOffset)
            . pack('V', 0);

        // Valid dummy coordinate: 45°30'0"
        $data = pack('V2', 45, 1)
            . pack('V2', 30, 1)
            . pack('V2', 0, 1);

        return $header . $ifd0 . $gpsIfd . $data;
    }

    /**
     * Builds a classic TIFF containing exactly one configurable GPS IFD entry.
     */
    private function buildClassicTiffWithSingleGpsEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        $componentSize = $this->bytesPerComponent($type);
        $dataSize      = $componentSize * $count;
        $dataBytes     = strlen($valueBytes) >= $dataSize
            ? substr($valueBytes, 0, $dataSize)
            : str_pad($valueBytes, $dataSize, "\0");

        $gpsIfdLength = 2 + 12 + 4;
        $dataOffset   = strlen($header . $ifd0) + $gpsIfdLength;

        $entry = pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count);

        if ($dataSize > 4) {
            $entry .= pack('V', $dataOffset);
            $payload = $dataBytes;
        } else {
            $entry .= str_pad($dataBytes, 4, "\0");
            $payload = '';
        }

        $gpsIfd = pack('v', 1)
            . $entry
            . pack('V', 0);

        return $header . $ifd0 . $gpsIfd . $payload;
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT     => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            default                   => 1,
        };
    }

    /**
     * Builds a classic TIFF with GPS coordinates and explicit GPSDateStamp/GPSTimeStamp.
     *
     * @param string                   $dateStamp GPSDateStamp ASCII payload (must include count bytes).
     * @param list<array{0:int,1:int}> $timeParts GPSTimeStamp RATIONAL triplet [hour, minute, second].
     */
    private function buildGpsDateTimeExample(string $dateStamp, array $timeParts): string
    {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        $gpsEntryCount = 6;
        $gpsIfdLength  = 2 + ($gpsEntryCount * 12) + 4;
        $gpsDataOffset = strlen($header . $ifd0) + $gpsIfdLength;
        $latOffset     = $gpsDataOffset;
        $lonOffset     = $latOffset + (3 * 8);
        $timeOffset    = $lonOffset + (3 * 8);
        $dateOffset    = $timeOffset + (3 * 8);

        $gpsIfd = pack('v', $gpsEntryCount)
            . pack('v', ExifTag::GPS_LATITUDE_REF)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', 'N')
            . pack('v', ExifTag::GPS_LATITUDE)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $latOffset)
            . pack('v', ExifTag::GPS_LONGITUDE_REF)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', 'E')
            . pack('v', ExifTag::GPS_LONGITUDE)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $lonOffset)
            . pack('v', ExifTag::GPS_TIME_STAMP)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $timeOffset)
            . pack('v', ExifTag::GPS_DATE_STAMP)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 11)
            . pack('V', $dateOffset)
            . pack('V', 0);

        $gpsData = pack('V2', 52, 1)
            . pack('V2', 31, 1)
            . pack('V2', 12000, 1000)
            . pack('V2', 13, 1)
            . pack('V2', 24, 1)
            . pack('V2', 17820, 1000)
            . pack('V2', $timeParts[0][0], $timeParts[0][1])
            . pack('V2', $timeParts[1][0], $timeParts[1][1])
            . pack('V2', $timeParts[2][0], $timeParts[2][1])
            . $dateStamp;

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }
}
