<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

/**
 * Helper methods that translate EXIF/TIFF values into PHP friendly scalars.
 */
final readonly class ValueConverters
{
    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param mixed $v The value to convert.
     *
     * @return float|null
     */
    public static function rationalToFloat(mixed $v): ?float
    {
        if (is_array($v) && count($v) === 2 && (int) $v[1] !== 0) {
            return (float) $v[0] / (float) $v[1];
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

        $lat = self::dmsToFloat($latRef, $latVal);
        $lon = self::dmsToFloat($lonRef, $lonVal);

        $alt = null;
        if (($e = $gps->get(ExifTag::GPS_ALTITUDE)) && is_array($e->value) && count($e->value) === 2 && (int) $e->value[1] !== 0) {
            $alt = $e->value[0] / $e->value[1];
            if (($ref = $gps->get(ExifTag::GPS_ALTITUDE_REF)) && (int) ($ref->value ?? 0) === 1) {
                $alt = -$alt;
            }
        }

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt];
    }

    /**
     * Converts EXIF GPS degrees/minutes/seconds to a float coordinate.
     *
     * @param string|null $ref Direction reference (N/E/S/W).
     * @param mixed       $val Rational triplet describing the coordinate.
     *
     * @return float|null
     */
    private static function dmsToFloat(?string $ref, mixed $val): ?float
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
}
