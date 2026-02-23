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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function strlen;
use function substr;

/**
 * Verifies GPS coordinate value tags enforce RATIONAL[3] layout per EXIF 3.0.
 * Tags: GPSLatitude (0x0002), GPSLongitude (0x0004), GPSDestLatitude (0x0014), GPSDestLongitude (0x0016).
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserGpsCoordinateTest extends TestCase
{
    /**
     * Accepts GPS coordinate tags encoded as RATIONAL[3] in the GPS IFD.
     */
    #[Test]
    #[DataProvider('provideGpsCoordinateTags')]
    public function acceptsGpsCoordinateTagsWithRationalCountThree(int $tag, string $_name, int $refTag, string $refValue, string $resultKey): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithGpsRefAndValue(
                $refTag,
                $refValue,
                $tag,
                TiffConst::TYPE_RATIONAL,
                3,
                pack('V2', 45, 1) . pack('V2', 30, 1) . pack('V2', 0, 1),
            ),
        );

        self::assertEqualsWithDelta(45.5, $result->gps()[$resultKey], 0.000001);
    }

    /**
     * Accepts GPS coordinate tags encoded as SRATIONAL[3] in the GPS IFD.
     * Legacy cameras wrote SRATIONAL instead of RATIONAL; both are functionally
     * equivalent for positive GPS coordinate values.
     */
    #[Test]
    #[DataProvider('provideGpsCoordinateTags')]
    public function acceptsGpsCoordinateTagsWithSrationalType(int $tag, string $_name, int $refTag, string $refValue, string $resultKey): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithGpsRefAndValue(
                $refTag,
                $refValue,
                $tag,
                TiffConst::TYPE_SRATIONAL,
                3,
                pack('V2', 45, 1) . pack('V2', 30, 1) . pack('V2', 0, 1),
            ),
        );

        self::assertEqualsWithDelta(45.5, $result->gps()[$resultKey], 0.000001);
    }

    /**
     * Accepts GPS coordinate tags regardless of TIFF type (Postel's Law).
     * Many real-world cameras write non-RATIONAL types for GPS coordinates.
     */
    #[Test]
    #[DataProvider('provideGpsCoordinateTags')]
    public function acceptsGpsCoordinateTagsWithNonRationalType(int $tag, string $_name, int $_refTag, string $_refValue, string $_resultKey): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithSingleGpsEntry(
                    $tag,
                    TiffConst::TYPE_ASCII,
                    3,
                    pack('V2', 45, 1) . pack('V2', 30, 1) . pack('V2', 0, 1),
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPS coordinate types');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Accepts GPSAltitude encoded as RATIONAL[1] in the GPS IFD.
     */
    #[Test]
    public function acceptsGpsAltitudeWithRationalCountOne(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithSingleGpsEntry(
                ExifTag::GPS_ALTITUDE,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 100, 1),
            ),
        );

        self::assertEqualsWithDelta(100.0, $result->gps()['alt'], 0.000001);
    }

    /**
     * Accepts GPSAltitude regardless of TIFF type (Postel's Law).
     */
    #[Test]
    public function acceptsGpsAltitudeWithNonRationalType(): void
    {
        try {
            (new TiffExifParser())->parseFromBlob(
                $this->buildClassicTiffWithSingleGpsEntry(
                    ExifTag::GPS_ALTITUDE,
                    TiffConst::TYPE_ASCII,
                    1,
                    pack('V2', 100, 1),
                ),
            );
        } catch (ParseError $e) {
            self::assertNotSame(1317, $e->getCode(), 'Type check must not reject non-RATIONAL GPSAltitude');

            return;
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Regression: valid coordinate decoding remains unchanged after enforcement.
     */
    #[Test]
    public function regressionValidCoordinateDecodingUnchanged(): void
    {
        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildGpsCoordinateExample(),
        );

        $gps = $result->gps();

        self::assertSame('N', $gps['lat_ref']);
        self::assertSame('E', $gps['lon_ref']);
        self::assertEqualsWithDelta(52.520833, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(13.409444, $gps['lon'], 0.000001);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: int, 3: string, 4: string}>
     */
    public static function provideGpsCoordinateTags(): iterable
    {
        yield 'GPSLatitude' => [ExifTag::GPS_LATITUDE, 'GPSLatitude', ExifTag::GPS_LATITUDE_REF, 'N', 'lat'];
        yield 'GPSLongitude' => [ExifTag::GPS_LONGITUDE, 'GPSLongitude', ExifTag::GPS_LONGITUDE_REF, 'E', 'lon'];
        yield 'GPSDestLatitude' => [ExifTag::GPS_DEST_LATITUDE, 'GPSDestLatitude', ExifTag::GPS_DEST_LATITUDE_REF, 'N', 'dest_lat'];
        yield 'GPSDestLongitude' => [ExifTag::GPS_DEST_LONGITUDE, 'GPSDestLongitude', ExifTag::GPS_DEST_LONGITUDE_REF, 'E', 'dest_lon'];
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
     * Builds a classic TIFF with a GPS ref entry and a configurable GPS value entry.
     */
    private function buildClassicTiffWithGpsRefAndValue(
        int $refTag,
        string $refValue,
        int $valueTag,
        int $valueType,
        int $valueCount,
        string $valueBytes,
    ): string {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        $componentSize = $this->bytesPerComponent($valueType);
        $dataSize      = $componentSize * $valueCount;
        $dataBytes     = strlen($valueBytes) >= $dataSize
            ? substr($valueBytes, 0, $dataSize)
            : str_pad($valueBytes, $dataSize, "\0");

        // GPS IFD: count(2) + 2×entry(12) + nextIfd(4)
        $gpsIfdLength = 2 + (2 * 12) + 4;
        $dataOffset   = strlen($header . $ifd0) + $gpsIfdLength;

        // Ref entry: ASCII[2] fits inline (≤ 4 bytes)
        $refEntry = pack('v', $refTag)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', $refValue);

        // Value entry
        $valEntry = pack('v', $valueTag)
            . pack('v', $valueType)
            . pack('V', $valueCount);

        if ($dataSize > 4) {
            $valEntry .= pack('V', $dataOffset);
            $payload = $dataBytes;
        } else {
            $valEntry .= str_pad($dataBytes, 4, "\0");
            $payload = '';
        }

        $gpsIfd = pack('v', 2)
            . $refEntry
            . $valEntry
            . pack('V', 0);

        return $header . $ifd0 . $gpsIfd . $payload;
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

    /**
     * Builds a classic TIFF with GPSLatitude/GPSLongitude for regression testing.
     * Berlin: 52°31'15" N, 13°24'34" E.
     */
    private function buildGpsCoordinateExample(): string
    {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        $gpsEntryCount = 4;
        $gpsIfdLength  = 2 + ($gpsEntryCount * 12) + 4;
        $gpsDataOffset = strlen($header . $ifd0) + $gpsIfdLength;
        $latOffset     = $gpsDataOffset;
        $lonOffset     = $latOffset + (3 * 8);

        $gpsIfd = pack('v', $gpsEntryCount)
            // GPSLatitudeRef = "N"
            . pack('v', ExifTag::GPS_LATITUDE_REF)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', 'N')
            // GPSLatitude = [52, 31, 15]
            . pack('v', ExifTag::GPS_LATITUDE)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $latOffset)
            // GPSLongitudeRef = "E"
            . pack('v', ExifTag::GPS_LONGITUDE_REF)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', 2)
            . pack('a4', 'E')
            // GPSLongitude = [13, 24, 34]
            . pack('v', ExifTag::GPS_LONGITUDE)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 3)
            . pack('V', $lonOffset)
            . pack('V', 0);

        $gpsData = pack('V2', 52, 1)
            . pack('V2', 31, 1)
            . pack('V2', 15, 1)
            . pack('V2', 13, 1)
            . pack('V2', 24, 1)
            . pack('V2', 34, 1);

        return $header . $ifd0 . $gpsIfd . $gpsData;
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
}
