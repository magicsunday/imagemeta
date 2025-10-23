<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use function count;
use function is_float;
use function is_int;

use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;

/**
 * Helper methods that translate EXIF/TIFF values into PHP friendly scalars.
 */
final readonly class ValueConverters
{
    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $v The value to convert.
     *
     * @return float|null
     */
    public static function rationalToFloat(int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $v): ?float
    {
        if ($v instanceof ExifRational) {
            if ($v->denominator === 0) {
                return null;
            }

            return (float) $v->numerator / (float) $v->denominator;
        }

        if ($v instanceof ExifRationalList) {
            $first = $v->values[0] ?? null;
            if ($first instanceof ExifRational) {
                return self::rationalToFloat($first);
            }

            return null;
        }

        if ($v instanceof ExifNumericList) {
            $first = $v->values[0] ?? null;
            if (is_int($first) || is_float($first)) {
                return (float) $first;
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

        $latPairs = $latVal instanceof ExifRationalList ? $latVal : null;
        $lonPairs = $lonVal instanceof ExifRationalList ? $lonVal : null;

        $lat = self::dmsToFloat($latRef, $latPairs);
        $lon = self::dmsToFloat($lonRef, $lonPairs);

        $alt = null;
        if (($e = $gps->get(ExifTag::GPS_ALTITUDE)) && $e->value instanceof ExifRational) {
            $alt = self::rationalToFloat($e->value);
            if ($alt !== null && ($ref = $gps->get(ExifTag::GPS_ALTITUDE_REF)) && (int) ($ref->value ?? 0) === 1) {
                $alt = -$alt;
            }
        }

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt];
    }

    /**
     * Converts EXIF GPS degrees/minutes/seconds to a float coordinate.
     *
     * @param string|null          $ref   Direction reference (N/E/S/W).
     * @param ExifRationalList|null $val Rational triplet describing the coordinate.
     *
     * @return float|null
     */
    private static function dmsToFloat(?string $ref, ?ExifRationalList $val): ?float
    {
        if (!is_string($ref) || $val === null || count($val->values) < 3) {
            return null;
        }
        $deg = self::rationalToFloat($val->values[0] ?? null);
        $min = self::rationalToFloat($val->values[1] ?? null);
        $sec = self::rationalToFloat($val->values[2] ?? null);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }
}
