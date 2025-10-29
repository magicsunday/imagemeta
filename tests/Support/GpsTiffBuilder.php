<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use LogicException;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;

use function array_map;
use function array_pad;
use function chr;
use function implode;
use function ord;
use function pack;
use function str_split;
use function strlen;
use function unpack;

/**
 * Builds synthetic Classic TIFF payloads containing EXIF GPS metadata for integration tests.
 *
 * @phpstan-type GpsStringDefinition = array{tag: int, type: int, count: positive-int, mode: 'string', payload: string}
 * @phpstan-type GpsRationalDefinition = array{tag: int, type: int, count: positive-int, mode: 'rationals', payload: list<array{numerator: int, denominator: int}>}
 * @phpstan-type GpsInlineDefinition = array{tag: int, type: int, count: positive-int, mode: 'inline', payload: int}
 * @phpstan-type GpsDefinition = GpsStringDefinition|GpsRationalDefinition|GpsInlineDefinition
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

        $definitions = self::gpsDefinitions();
        /** @var list<GpsDefinition> $definitions */
        $definitions = $definitions;

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

        foreach ($definitions as $definition) {
            /** @var GpsDefinition $definition */
            $definition = $definition;

            $tag     = $definition['tag'];
            $type    = $definition['type'];
            $count   = $definition['count'];
            $mode    = $definition['mode'];
            $payload = $definition['payload'];

            switch ($mode) {
                case 'string':
                    if (!is_string($payload)) {
                        throw new LogicException('String payload expected.');
                    }

                    if (strlen($payload) <= 4) {
                        $gpsEntries[] = self::packClassicEntry($tag, $type, $count, self::inlineString($payload));
                        break;
                    }

                    $offset       = $addData($payload);
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $offset);
                    break;
                case 'rationals':
                    if (!is_array($payload)) {
                        throw new LogicException('Rational payload expected.');
                    }

                    /** @var list<array{numerator: int, denominator: int}> $payload */
                    $buffer = '';
                    foreach ($payload as $components) {
                        $buffer .= self::packRational($components['numerator'], $components['denominator']);
                    }

                    $offset       = $addData($buffer);
                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $offset);
                    break;
                case 'inline':
                    if (!is_int($payload)) {
                        throw new LogicException('Inline payload expected to be integer.');
                    }

                    $gpsEntries[] = self::packClassicEntry($tag, $type, $count, $payload);
                    break;
            }
        }

        $gpsIfd = pack('v', $gpsEntryCount) . implode('', $gpsEntries) . pack('V', 0);

        return $header . $ifd0 . $gpsIfd . $gpsData;
    }

    /**
     * @phpstan-return list<GpsDefinition>
     */
    private static function gpsDefinitions(): array
    {
        return [
            self::stringDefinition(ExifTag::GPS_VERSION_ID, 2, 9, '3.0.0.0' . chr(0)),
            self::stringDefinition(ExifTag::GPS_LATITUDE_REF, 2, 2, "N\0"),
            self::rationalDefinition(ExifTag::GPS_LATITUDE, 5, 3, [
                ['numerator' => 51, 'denominator' => 1],
                ['numerator' => 30, 'denominator' => 1],
                ['numerator' => 0, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_LONGITUDE_REF, 2, 2, "E\0"),
            self::rationalDefinition(ExifTag::GPS_LONGITUDE, 5, 3, [
                ['numerator' => 8, 'denominator' => 1],
                ['numerator' => 30, 'denominator' => 1],
                ['numerator' => 0, 'denominator' => 1],
            ]),
            self::inlineDefinition(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            self::rationalDefinition(ExifTag::GPS_ALTITUDE, 5, 1, [
                ['numerator' => 150, 'denominator' => 1],
            ]),
            self::rationalDefinition(ExifTag::GPS_TIME_STAMP, 5, 3, [
                ['numerator' => 12, 'denominator' => 1],
                ['numerator' => 34, 'denominator' => 1],
                ['numerator' => 56789, 'denominator' => 1000],
            ]),
            self::stringDefinition(ExifTag::GPS_DATE_STAMP, 2, 11, "2024:05:06\0"),
            self::stringDefinition(ExifTag::GPS_SPEED_REF, 2, 2, "K\0"),
            self::rationalDefinition(ExifTag::GPS_SPEED, 5, 1, [
                ['numerator' => 72000, 'denominator' => 1000],
            ]),
            self::stringDefinition(ExifTag::GPS_TRACK_REF, 2, 2, "T\0"),
            self::rationalDefinition(ExifTag::GPS_TRACK, 5, 1, [
                ['numerator' => 450, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, "M\0"),
            self::rationalDefinition(ExifTag::GPS_IMG_DIRECTION, 5, 1, [
                ['numerator' => 405, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, "N\0"),
            self::rationalDefinition(ExifTag::GPS_DEST_LATITUDE, 5, 3, [
                ['numerator' => 41, 'denominator' => 1],
                ['numerator' => 0, 'denominator' => 1],
                ['numerator' => 0, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 2, "E\0"),
            self::rationalDefinition(ExifTag::GPS_DEST_LONGITUDE, 5, 3, [
                ['numerator' => 8, 'denominator' => 1],
                ['numerator' => 30, 'denominator' => 1],
                ['numerator' => 0, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_DEST_BEARING_REF, 2, 2, "T\0"),
            self::rationalDefinition(ExifTag::GPS_DEST_BEARING, 5, 1, [
                ['numerator' => 765, 'denominator' => 1],
            ]),
            self::stringDefinition(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, "K\0"),
            self::rationalDefinition(ExifTag::GPS_DEST_DISTANCE, 5, 1, [
                ['numerator' => 42, 'denominator' => 1],
            ]),
            self::inlineDefinition(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            self::rationalDefinition(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, [
                ['numerator' => 15, 'denominator' => 10],
            ]),
        ];
    }

    /**
     * @param list<array{numerator: int, denominator: int}> $payload
     *
     * @phpstan-param positive-int $count
     *
     * @phpstan-return GpsRationalDefinition
     */
    private static function rationalDefinition(int $tag, int $type, int $count, array $payload): array
    {
        return [
            'tag'     => $tag,
            'type'    => $type,
            'count'   => $count,
            'mode'    => 'rationals',
            'payload' => $payload,
        ];
    }

    /**
     * @param string $payload
     *
     * @phpstan-param positive-int $count
     *
     * @phpstan-return GpsStringDefinition
     */
    private static function stringDefinition(int $tag, int $type, int $count, string $payload): array
    {
        return [
            'tag'     => $tag,
            'type'    => $type,
            'count'   => $count,
            'mode'    => 'string',
            'payload' => $payload,
        ];
    }

    /**
     * @param int $payload
     *
     * @phpstan-param positive-int $count
     *
     * @phpstan-return GpsInlineDefinition
     */
    private static function inlineDefinition(int $tag, int $type, int $count, int $payload): array
    {
        return [
            'tag'     => $tag,
            'type'    => $type,
            'count'   => $count,
            'mode'    => 'inline',
            'payload' => $payload,
        ];
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
        /** @var list<int<0, 255>> $bytes */
        $bytes = array_map(ord(...), str_split($value));

        return self::inlineBytes($bytes);
    }

    /**
     * Converts up to four bytes into an inline DWORD representation.
     *
     * @param list<int<0, 255>> $bytes
     */
    private static function inlineBytes(array $bytes): int
    {
        $bytes = array_pad($bytes, 4, 0);
        /** @var list<int<0, 255>> $bytes */
        $bytes = $bytes;

        $packed = unpack('V', pack('C4', $bytes[0], $bytes[1], $bytes[2], $bytes[3]));
        if ($packed === false) {
            throw new LogicException('Unable to unpack inline bytes.');
        }

        /** @var array<int, int> $packed */
        return $packed[1];
    }
}
