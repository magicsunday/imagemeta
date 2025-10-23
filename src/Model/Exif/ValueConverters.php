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
use function is_string;

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
        $latRefEntry = $gps->get(ExifTag::GPS_LATITUDE_REF);
        $latValEntry = $gps->get(ExifTag::GPS_LATITUDE);
        $lonRefEntry = $gps->get(ExifTag::GPS_LONGITUDE_REF);
        $lonValEntry = $gps->get(ExifTag::GPS_LONGITUDE);

        $latRef = $latRefEntry?->value;
        $latVal = $latValEntry?->value;
        $lonRef = $lonRefEntry?->value;
        $lonVal = $lonValEntry?->value;

        $latPairs = $latVal instanceof ExifRationalList ? $latVal : null;
        $lonPairs = $lonVal instanceof ExifRationalList ? $lonVal : null;

        $lat = self::dmsToFloat(is_string($latRef) ? $latRef : null, $latPairs);
        $lon = self::dmsToFloat(is_string($lonRef) ? $lonRef : null, $lonPairs);

        $alt      = null;
        $altEntry = $gps->get(ExifTag::GPS_ALTITUDE);
        if ($altEntry !== null && $altEntry->value instanceof ExifRational) {
            $alt = self::rationalToFloat($altEntry->value);

            $altRef = $gps->get(ExifTag::GPS_ALTITUDE_REF);
            if ($alt !== null && $altRef !== null) {
                $refValue = $altRef->value;
                if (is_int($refValue) && $refValue === 1) {
                    $alt = -$alt;
                }
            }
        }

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt];
    }

    /**
     * Converts EXIF GPS degrees/minutes/seconds to a float coordinate.
     *
     * @param string|null           $ref Direction reference (N/E/S/W).
     * @param ExifRationalList|null $val Rational triplet describing the coordinate.
     *
     * @return float|null
     */
    private static function dmsToFloat(?string $ref, ?ExifRationalList $val): ?float
    {
        if (!is_string($ref) || $val === null || count($val->values) < 3) {
            return null;
        }
        $values = $val->values;
        $deg    = self::rationalToFloat($values[0]);
        $min    = self::rationalToFloat($values[1]);
        $sec    = self::rationalToFloat($values[2]);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }
}
