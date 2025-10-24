<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;

use function array_map;
use function array_pad;
use function ord;
use function pack;
use function str_split;
use function strlen;
use function unpack;

/**
 * Builds synthetic Classic TIFF payloads containing EXIF GPS metadata for integration tests.
 */
final class GpsTiffBuilder
{
    /**
     * Creates a Classic TIFF blob with a populated GPS IFD exercising EXIF 3.0 tags.
     */
    public static function buildClassicGpsTiff(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $ifd0EntryCount = 1;
        $ifd0Size       = 2 + 12 + 4;
        $gpsIfdOffset   = 8 + $ifd0Size;

        $ifd0 = pack('v', $ifd0EntryCount)
            . self::packClassicEntry(ExifTag::GPS_IFD_POINTER, 4, 1, $gpsIfdOffset)
            . pack('V', 0);

        $definitions = [
            [ExifTag::GPS_VERSION_ID, 1, 4, 'bytes', [3, 0, 0, 0]],
            [ExifTag::GPS_LATITUDE_REF, 2, 2, 'string', "N\0"],
            [ExifTag::GPS_LATITUDE, 5, 3, 'rationals', [[51, 1], [30, 1], [0, 1]]],
            [ExifTag::GPS_LONGITUDE_REF, 2, 2, 'string', "E\0"],
            [ExifTag::GPS_LONGITUDE, 5, 3, 'rationals', [[8, 1], [30, 1], [0, 1]]],
            [ExifTag::GPS_ALTITUDE_REF, 1, 1, 'inline', 0],
            [ExifTag::GPS_ALTITUDE, 5, 1, 'rationals', [[150, 1]]],
            [ExifTag::GPS_TIME_STAMP, 5, 3, 'rationals', [[12, 1], [34, 1], [56789, 1000]]],
            [ExifTag::GPS_DATE_STAMP, 2, 11, 'string', "2024:05:06\0"],
            [ExifTag::GPS_SPEED_REF, 2, 2, 'string', "K\0"],
            [ExifTag::GPS_SPEED, 5, 1, 'rationals', [[72000, 1000]]],
            [ExifTag::GPS_TRACK_REF, 2, 2, 'string', "T\0"],
            [ExifTag::GPS_TRACK, 5, 1, 'rationals', [[450, 1]]],
            [ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, 'string', "M\0"],
            [ExifTag::GPS_IMG_DIRECTION, 5, 1, 'rationals', [[405, 1]]],
            [ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, 'string', "N\0"],
            [ExifTag::GPS_DEST_LATITUDE, 5, 3, 'rationals', [[41, 1], [0, 1], [0, 1]]],
            [ExifTag::GPS_DEST_LONGITUDE_REF, 2, 2, 'string', "E\0"],
            [ExifTag::GPS_DEST_LONGITUDE, 5, 3, 'rationals', [[8, 1], [30, 1], [0, 1]]],
            [ExifTag::GPS_DEST_BEARING_REF, 2, 2, 'string', "T\0"],
            [ExifTag::GPS_DEST_BEARING, 5, 1, 'rationals', [[765, 1]]],
            [ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, 'string', "K\0"],
            [ExifTag::GPS_DEST_DISTANCE, 5, 1, 'rationals', [[42, 1]]],
            [ExifTag::GPS_DIFFERENTIAL, 3, 1, 'inline', 2],
            [ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, 'rationals', [[15, 10]]],
        ];

        $gpsEntryCount = count($definitions);
        $gpsIfdSize    = 2 + ($gpsEntryCount * 12) + 4;
        $dataBase      = $gpsIfdOffset + $gpsIfdSize;

        $gpsEntries = [];
        $gpsData    = '';

        $addData = static function (string $value) use (&$gpsData, $dataBase): int {
            $offset = $dataBase + strlen($gpsData);
            $gpsData .= $value;

            return $offset;
        };

        foreach ($definitions as [$tag, $type, $count, $mode, $payload]) {
            switch ($mode) {
                case 'bytes':
                    $value        = self::inlineBytes($payload);
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $value);
                    break;
                case 'string':
                    if (strlen($payload) <= 4) {
                        $gpsEntries[] = self::packClassicEntry($tag, $type, $count, self::inlineString($payload));
                        break;
                    }

                    $offset       = $addData($payload);
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $offset);
                    break;
                case 'rationals':
                    $buffer = '';
                    foreach ($payload as [$numerator, $denominator]) {
                        $buffer .= self::packRational($numerator, $denominator);
                    }

                    $offset       = $addData($buffer);
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $offset);
                    break;
                case 'inline':
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, (int) $payload);
                    break;
            }
        }

        $gpsIfd = pack('v', $gpsEntryCount) . implode('', $gpsEntries) . pack('V', 0);

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }

    /**
     * Packs a Classic TIFF directory entry.
     */
    private static function packClassicEntry(int $tag, int $type, int $count, int $valueOrOffset): string
    {
        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valueOrOffset);
    }

    /**
     * Packs a rational number into TIFF numerator/denominator format.
     */
    private static function packRational(int $numerator, int $denominator): string
    {
        return pack('V', $numerator) . pack('V', $denominator);
    }

    /**
     * Converts a string containing up to four bytes into an inline DWORD value.
     */
    private static function inlineString(string $value): int
    {
        return self::inlineBytes(array_map(ord(...), str_split($value)));
    }

    /**
     * Converts up to four bytes into an inline DWORD representation.
     *
     * @param array<int> $bytes
     */
    private static function inlineBytes(array $bytes): int
    {
        $bytes = array_pad($bytes, 4, 0);

        return unpack('V', pack('C4', $bytes[0], $bytes[1], $bytes[2], $bytes[3]))[1];
    }
}
