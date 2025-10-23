<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the TIFF EXIF reader with synthetic Classic TIFF and BigTIFF payloads.
 */
final class TiffExifReaderTest extends TestCase
{
    /**
     * Provides representative Classic TIFF and BigTIFF payloads.
     *
     * @return iterable<string, array{0:string,1:string}>
     */
    public static function provideValidTiffPayloads(): iterable
    {
        yield 'classic' => [
            self::buildClassicTiffBlob(),
            'assertClassicDocument',
        ];

        yield 'big_tiff' => [
            self::buildBigTiffBlob(),
            'assertBigTiffDocument',
        ];
    }

    /**
     * Verifies that valid TIFF payloads are parsed into the expected IFD hierarchy.
     *
     * @param string $blob      Binary TIFF/EXIF payload.
     * @param string $assertion Assertion method name to execute for the parsed document.
     */
    #[Test]
    #[DataProvider('provideValidTiffPayloads')]
    public function parsesValidPayloads(string $blob, string $assertion): void
    {
        $reader = new TiffExifReader();
        $doc    = $reader->parseFromBlob($blob);

        call_user_func([self::class, $assertion], $doc);
    }

    /**
     * Ensures that TIFF blobs with an unsupported magic identifier are rejected.
     */
    #[Test]
    public function rejectsUnknownMagic(): void
    {
        $blob = 'II' . pack('v', 0x1234) . str_repeat("\0", 4);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unknown TIFF magic');

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures that invalid pointer offsets trigger bounds checking errors.
     */
    #[Test]
    public function failsOnOutOfRangeIfdPointer(): void
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $entries = self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 4096);
        $ifd0    = pack('v', 1) . $entries . pack('V', 0);

        $blob = $header . $ifd0;

        $this->expectException(BoundsError::class);

        (new TiffExifReader())->parseFromBlob($blob);
    }

    /**
     * Ensures BYTE tags with multiple values preserve each byte.
     */
    #[Test]
    public function preservesMultiValueByteTags(): void
    {
        $blob = self::buildClassicMultiByteTagBlob();

        $document = (new TiffExifReader())->parseFromBlob($blob);

        $entry = $document->ifd0->get(ExifTag::GPS_ALTITUDE_REF);
        self::assertNotNull($entry);
        self::assertSame([1, 2, 3], $entry->value);
    }

    /**
     * Asserts the decoded values of the synthetic Classic TIFF payload.
     *
     * @param ExifDocument $doc Parsed document returned by the TIFF reader.
     */
    private static function assertClassicDocument(ExifDocument $doc): void
    {
        self::assertSame('Canon', $doc->ifd0->get(ExifTag::MAKE)?->value);
        self::assertSame(1, $doc->ifd0->get(ExifTag::ORIENTATION)?->value);

        $exifIfd = $doc->exifIfd;
        self::assertNotNull($exifIfd);
        self::assertSame(200, $exifIfd->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)?->value);
        self::assertSame([28, 10], $exifIfd->get(ExifTag::F_NUMBER)?->value);

        $interop = $doc->interopIfd;
        self::assertNotNull($interop);
        self::assertSame('R98', $interop->get(0x0001)?->value);

        $gpsIfd = $doc->gpsIfd;
        self::assertNotNull($gpsIfd);
        self::assertSame([[40, 1], [30, 1], [15, 1]], $gpsIfd->get(ExifTag::GPS_LATITUDE)?->value);
        self::assertSame([[70, 1], [45, 1], [30, 1]], $gpsIfd->get(ExifTag::GPS_LONGITUDE)?->value);
        self::assertSame([150, 1], $gpsIfd->get(ExifTag::GPS_ALTITUDE)?->value);

        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.504166, $gps['lat'], 1e-6);
        self::assertEqualsWithDelta(70.758333, $gps['lon'], 1e-6);
        self::assertEqualsWithDelta(-150.0, $gps['alt'], 1e-3);
    }

    /**
     * Asserts the decoded values of the synthetic BigTIFF payload.
     *
     * @param ExifDocument $doc Parsed document returned by the TIFF reader.
     */
    private static function assertBigTiffDocument(ExifDocument $doc): void
    {
        self::assertSame('BigCamXL', $doc->ifd0->get(ExifTag::MAKE)?->value);
        self::assertSame(3, $doc->ifd0->get(ExifTag::ORIENTATION)?->value);

        $exifIfd = $doc->exifIfd;
        self::assertNotNull($exifIfd);
        self::assertSame(320, $exifIfd->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)?->value);
        self::assertSame([35, 10], $exifIfd->get(ExifTag::FOCAL_LENGTH)?->value);

        $interop = $doc->interopIfd;
        self::assertNotNull($interop);
        self::assertSame('R98', $interop->get(0x0002)?->value);

        $gpsIfd = $doc->gpsIfd;
        self::assertNotNull($gpsIfd);
        self::assertSame([[51, 1], [30, 1], [15, 1]], $gpsIfd->get(ExifTag::GPS_LATITUDE)?->value);
        self::assertSame([[8, 1], [12, 1], [30, 1]], $gpsIfd->get(ExifTag::GPS_LONGITUDE)?->value);
        self::assertSame([500, 10], $gpsIfd->get(ExifTag::GPS_ALTITUDE)?->value);

        $gps = $doc->gps();
        self::assertEqualsWithDelta(51.504167, $gps['lat'], 1e-6);
        self::assertEqualsWithDelta(-8.208333, $gps['lon'], 1e-6);
        self::assertEqualsWithDelta(-50.0, $gps['alt'], 1e-3);
    }

    /**
     * Builds a Classic TIFF little-endian EXIF payload with nested IFDs.
     */
    private static function buildClassicTiffBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0Entries = [
            self::packClassicEntry(ExifTag::MAKE, 2, 6, 62),
            self::packClassicEntry(ExifTag::ORIENTATION, 3, 1, 1),
            self::packClassicEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 68),
            self::packClassicEntry(ExifTag::GPS_IFD_POINTER, 4, 1, 136),
        ];
        $ifd0 = pack('v', count($ifd0Entries)) . implode('', $ifd0Entries) . pack('V', 0);

        $blob = $header . $ifd0;
        $blob .= "Canon\0"; // offset 62

        $exifEntries = [
            self::packClassicEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            self::packClassicEntry(ExifTag::F_NUMBER, 5, 1, 110),
            self::packClassicEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, 118),
        ];
        $blob .= pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        $blob .= pack('V', 28) . pack('V', 10); // offset 110

        $interopEntries = [
            self::packClassicEntry(0x0001, 2, 4, self::inlineAsciiToInt('R98', 4)),
        ];
        $blob .= pack('v', count($interopEntries)) . implode('', $interopEntries) . pack('V', 0);

        $gpsEntries = [
            self::packClassicEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, self::inlineAsciiToInt('N', 4)),
            self::packClassicEntry(ExifTag::GPS_LATITUDE, 5, 3, 214),
            self::packClassicEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, self::inlineAsciiToInt('E', 4)),
            self::packClassicEntry(ExifTag::GPS_LONGITUDE, 5, 3, 238),
            self::packClassicEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            self::packClassicEntry(ExifTag::GPS_ALTITUDE, 5, 1, 262),
        ];
        $blob .= pack('v', count($gpsEntries)) . implode('', $gpsEntries) . pack('V', 0);

        $blob .= self::packRationalTripletLE([40, 1], [30, 1], [15, 1]);
        $blob .= self::packRationalTripletLE([70, 1], [45, 1], [30, 1]);
        $blob .= pack('V', 150) . pack('V', 1);

        return $blob;
    }

    /**
     * Builds a Classic TIFF blob containing a multi-value BYTE tag.
     */
    private static function buildClassicMultiByteTagBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $inlineValue = self::inlineBytes([1, 2, 3], 4);
        $entry       = self::packClassicEntry(ExifTag::GPS_ALTITUDE_REF, 1, 3, $inlineValue);
        $ifd0        = pack('v', 1) . $entry . pack('V', 0);

        return $header . $ifd0;
    }

    /**
     * Builds a BigTIFF little-endian EXIF payload with nested IFDs.
     */
    private static function buildBigTiffBlob(): string
    {
        $header = 'II'
            . pack('v', 0x002B)
            . pack('v', 8)
            . pack('v', 0)
            . pack('V', 16)
            . pack('V', 0);

        $ifd0Entries = [
            self::packBigTiffEntry(ExifTag::MAKE, 2, 9, 112),
            self::packBigTiffEntry(ExifTag::ORIENTATION, 3, 1, 3),
            self::packBigTiffEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 128),
            self::packBigTiffEntry(ExifTag::GPS_IFD_POINTER, 4, 1, 256),
        ];
        $ifd0 = pack('V', count($ifd0Entries)) . pack('V', 0) . implode('', $ifd0Entries) . pack('V', 0) . pack('V', 0);

        $blob = $header . $ifd0;
        $blob .= "BigCamXL\0"; // offset 112
        $blob .= str_repeat("\0", 7); // pad to 128

        $exifEntries = [
            self::packBigTiffEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 320),
            self::packBigTiffEntry(
                ExifTag::FOCAL_LENGTH,
                5,
                1,
                self::toLittleEndianInteger(self::packRationalLE(35, 10)),
            ),
            self::packBigTiffEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, 220),
        ];
        $blob .= pack('V', count($exifEntries)) . pack('V', 0) . implode('', $exifEntries) . pack('V', 0) . pack('V', 0);
        $blob .= str_repeat("\0", 16); // pad to 220

        $interopEntries = [
            self::packBigTiffEntry(0x0002, 2, 4, self::inlineAsciiToInt('R98', 8)),
        ];
        $blob .= pack('V', count($interopEntries)) . pack('V', 0) . implode('', $interopEntries) . pack('V', 0) . pack('V', 0);

        $gpsEntries = [
            self::packBigTiffEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, self::inlineAsciiToInt('N', 8)),
            self::packBigTiffEntry(ExifTag::GPS_LATITUDE, 5, 3, 392),
            self::packBigTiffEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, self::inlineAsciiToInt('W', 8)),
            self::packBigTiffEntry(ExifTag::GPS_LONGITUDE, 5, 3, 416),
            self::packBigTiffEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            self::packBigTiffEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                self::toLittleEndianInteger(self::packRationalLE(500, 10)),
            ),
        ];
        $blob .= pack('V', count($gpsEntries)) . pack('V', 0) . implode('', $gpsEntries) . pack('V', 0) . pack('V', 0);

        $blob .= self::packRationalTripletLE([51, 1], [30, 1], [15, 1]);
        $blob .= self::packRationalTripletLE([8, 1], [12, 1], [30, 1]);

        return $blob;
    }

    /**
     * Packs a Classic TIFF directory entry.
     *
     * @param int $tag           TIFF tag identifier.
     * @param int $type          TIFF field type code.
     * @param int $count         Number of values represented.
     * @param int $valueOrOffset Inline value or data offset.
     */
    private static function packClassicEntry(int $tag, int $type, int $count, int $valueOrOffset): string
    {
        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valueOrOffset);
    }

    /**
     * Packs a BigTIFF directory entry in little-endian order.
     *
     * @param int $tag           TIFF tag identifier.
     * @param int $type          TIFF field type code.
     * @param int $count         Number of values represented.
     * @param int $valueOrOffset Inline value or data offset.
     */
    private static function packBigTiffEntry(int $tag, int $type, int $count, int $valueOrOffset): string
    {
        [$countLo, $countHi] = self::splitUInt64($count);
        [$valueLo, $valueHi] = self::splitUInt64($valueOrOffset);

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $countLo)
            . pack('V', $countHi)
            . pack('V', $valueLo)
            . pack('V', $valueHi);
    }

    /**
     * Converts a short ASCII string into an inline integer value for TIFF entries.
     *
     * @param string $ascii ASCII string to encode.
     * @param int    $width Inline storage width (4 for Classic, 8 for BigTIFF).
     */
    private static function inlineAsciiToInt(string $ascii, int $width): int
    {
        $bytes = str_pad($ascii, $width, "\0");

        return self::toLittleEndianInteger($bytes);
    }

    /**
     * Packs raw bytes into an inline integer value for Classic TIFF entries.
     *
     * @param array<int, int> $values Byte values to encode.
     * @param int              $width Inline storage width.
     */
    private static function inlineBytes(array $values, int $width): int
    {
        $bytes = pack('C*', ...$values);
        $bytes = str_pad($bytes, $width, "\0");

        return self::toLittleEndianInteger($bytes);
    }

    /**
     * Packs three rationals for GPS coordinates using little-endian order.
     *
     * @param array{0:int,1:int} $deg Degree component as a rational pair.
     * @param array{0:int,1:int} $min Minute component as a rational pair.
     * @param array{0:int,1:int} $sec Second component as a rational pair.
     */
    private static function packRationalTripletLE(array $deg, array $min, array $sec): string
    {
        return self::packRationalLE($deg[0], $deg[1])
            . self::packRationalLE($min[0], $min[1])
            . self::packRationalLE($sec[0], $sec[1]);
    }

    /**
     * Packs a single rational number using little-endian byte order.
     *
     * @param int $numerator   Rational numerator.
     * @param int $denominator Rational denominator.
     */
    private static function packRationalLE(int $numerator, int $denominator): string
    {
        return pack('V', $numerator) . pack('V', $denominator);
    }

    /**
     * Converts a little-endian byte string into an integer value.
     *
     * @param string $bytes Input bytes (LSB first).
     */
    private static function toLittleEndianInteger(string $bytes): int
    {
        $value = 0;
        $len   = strlen($bytes);

        for ($i = $len - 1; $i >= 0; --$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * Splits an unsigned 64-bit integer into low/high 32-bit components.
     *
     * @param int $value Input integer to split.
     *
     * @return array{0:int,1:int}
     */
    private static function splitUInt64(int $value): array
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;

        return [$lo, $hi];
    }
}
