<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use function array_is_list;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Helper methods that translate EXIF/TIFF values into PHP friendly scalars.
 *
 * @phpstan-import-type ExifRational from IfdEntry
 * @phpstan-import-type ExifRationalList from IfdEntry
 * @phpstan-import-type ExifValue from IfdEntry
 */
final readonly class ValueConverters
{
    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param ExifValue|null $v The value to convert.
     *
     * @return float|null
     */
    public static function rationalToFloat(int|float|string|array|null $v): ?float
    {
        if (is_array($v)) {
            $pair = self::normaliseRationalPair($v);
            if ($pair !== null && $pair[1] !== 0) {
                return (float) $pair[0] / (float) $pair[1];
            }

            $list = self::normaliseRationalList($v);
            if ($list !== null && isset($list[0]) && $list[0][1] !== 0) {
                return (float) $list[0][0] / (float) $list[0][1];
            }

            return null;
        }

        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        return null;
    }

    /**
     * Extracts GPS latitude, longitude and altitude information from an IFD.
     *
     * @param Ifd $gps The GPS IFD containing coordinate tags.
     *
     * @return array{lat:?float,lon:?float,alt:?float}
     */
    public static function gpsFromIfd(Ifd $gps): array
    {
        $latRef = $gps->get(ExifTag::GPS_LATITUDE_REF)?->value ?? null;
        $latVal = $gps->get(ExifTag::GPS_LATITUDE)?->value ?? null;
        $lonRef = $gps->get(ExifTag::GPS_LONGITUDE_REF)?->value ?? null;
        $lonVal = $gps->get(ExifTag::GPS_LONGITUDE)?->value ?? null;

        $latPairs = is_array($latVal) ? self::normaliseRationalList($latVal) : null;
        $lonPairs = is_array($lonVal) ? self::normaliseRationalList($lonVal) : null;

        $lat = self::dmsToFloat($latRef, $latPairs);
        $lon = self::dmsToFloat($lonRef, $lonPairs);

        $alt = null;
        if (($e = $gps->get(ExifTag::GPS_ALTITUDE)) && is_array($e->value)) {
            $altPair = self::normaliseRationalPair($e->value);
            if ($altPair !== null && $altPair[1] !== 0) {
                $alt = $altPair[0] / $altPair[1];
            }
            if ($alt !== null && ($ref = $gps->get(ExifTag::GPS_ALTITUDE_REF)) && (int) ($ref->value ?? 0) === 1) {
                $alt = -$alt;
            }
        }

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt];
    }

    /**
     * Converts EXIF GPS degrees/minutes/seconds to a float coordinate.
     *
     * @param string|null        $ref   Direction reference (N/E/S/W).
     * @param ExifRationalList|null $val Rational triplet describing the coordinate.
     *
     * @return float|null
     */
    private static function dmsToFloat(?string $ref, ?array $val): ?float
    {
        if (!is_string($ref) || !is_array($val) || count($val) < 3) {
            return null;
        }
        $deg = self::rationalToFloat($val[0] ?? null);
        $min = self::rationalToFloat($val[1] ?? null);
        $sec = self::rationalToFloat($val[2] ?? null);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }

    /**
     * Normalises a potential rational pair into a strict two-element array.
     *
     * @param array<int, mixed> $value Candidate value from EXIF data.
     *
     * @return ExifRational|null
     */
    private static function normaliseRationalPair(array $value): ?array
    {
        if (!array_is_list($value) || count($value) !== 2) {
            return null;
        }

        $num = $value[0] ?? null;
        $den = $value[1] ?? null;

        if (!is_int($num) || !is_int($den)) {
            return null;
        }

        return [$num, $den];
    }

    /**
     * Validates that the provided value is a list of rational pairs.
     *
     * @param array<int, mixed> $value Candidate value from EXIF data.
     *
     * @return ExifRationalList|null
     */
    private static function normaliseRationalList(array $value): ?array
    {
        if (!array_is_list($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                return null;
            }

            $pair = self::normaliseRationalPair($item);
            if ($pair === null) {
                return null;
            }

            $list[] = $pair;
        }

        return $list;
    }
}
