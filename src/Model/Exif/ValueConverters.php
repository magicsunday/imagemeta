<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use MagicSunday\ImageMeta\Value\FlashInfo;

use function abs;
use function count;
use function ctype_digit;
use function explode;
use function floor;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function preg_match;
use function round;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function strtoupper;
use function trim;

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
     * Converts a stored APEX aperture value into a traditional f-number.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value The APEX value to convert.
     */
    public static function apexToFNumber(int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value): ?float
    {
        $apex = self::rationalToFloat($value);

        if ($apex === null && is_string($value) && is_numeric($value)) {
            $apex = (float) $value;
        }

        if ($apex === null) {
            return null;
        }

        return 2 ** ($apex / 2.0);
    }

    /**
     * Converts a GPS speed measurement into metres per second.
     *
     * @param string|null                                                 $ref   Speed reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value The measured value.
     */
    public static function gpsSpeedToMs(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?float {
        if (!is_string($ref)) {
            return null;
        }

        $numeric = self::rationalToFloat($value);
        if ($numeric === null && is_string($value) && is_numeric($value)) {
            $numeric = (float) $value;
        }

        if ($numeric === null) {
            return null;
        }

        $normalizedRef = strtoupper(trim($ref));

        return match ($normalizedRef) {
            'K' => $numeric / 3.6,
            'M' => $numeric * 0.44704,
            'N' => $numeric * 0.5144444444444444,
            default => null,
        };
    }

    /**
     * Converts the EXIF flash bit field into a typed value object.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Flash tag value representation.
     */
    public static function flashFromShort(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?FlashInfo {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if ($first === null) {
                return null;
            }

            $value = $first;
        }

        if ($value instanceof ExifRational) {
            if ($value->denominator === 0) {
                return null;
            }

            $value = (int) round((float) $value->numerator / (float) $value->denominator);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return self::flashFromShort($first);
        }

        if (is_float($value) || is_int($value)) {
            return FlashInfo::fromExifValue((int) $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return FlashInfo::fromExifValue((int) $value);
        }

        return null;
    }

    /**
     * Normalises EXIF offset time values to a canonical "+HH:MM" representation.
     *
     * @param int|float|string|null $value The raw offset value.
     */
    public static function parseOffsetString(int|float|string|null $value): ?string
    {
        $components = self::parseOffsetComponents($value);

        if ($components === null) {
            return null;
        }

        $sign = $components['sign'] < 0 ? '-' : '+';

        return sprintf('%s%02d:%02d', $sign, $components['hours'], $components['minutes']);
    }

    /**
     * Converts an EXIF offset time value to minutes relative to UTC.
     *
     * @param int|float|string|null $value The raw offset value.
     */
    public static function offsetToMinutes(int|float|string|null $value): ?int
    {
        $components = self::parseOffsetComponents($value);

        if ($components === null) {
            return null;
        }

        $minutes = $components['hours'] * 60 + $components['minutes'];

        return $components['sign'] < 0 ? -$minutes : $minutes;
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
        if ($altEntry instanceof IfdEntry && $altEntry->value instanceof ExifRational) {
            $alt = self::rationalToFloat($altEntry->value);

            $altRef = $gps->get(ExifTag::GPS_ALTITUDE_REF);
            if ($alt !== null && $altRef instanceof IfdEntry) {
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
        if (!is_string($ref) || !$val instanceof ExifRationalList || count($val->values) < 3) {
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

    /**
     * Parses numeric and textual offset encodings into sign, hour and minute components.
     *
     * @param int|float|string|null $value The raw value to parse.
     *
     * @return array{sign:int, hours:int, minutes:int}|null
     */
    private static function parseOffsetComponents(int|float|string|null $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $raw = is_string($value) ? trim($value) : (string) $value;
        $raw = str_replace(["−", '–', '—'], '-', $raw);
        $raw = str_replace(['＋'], '+', $raw);

        if ($raw === '') {
            return null;
        }

        $upper = strtoupper($raw);
        if ($upper === 'Z' || $upper === 'UTC' || $upper === 'GMT') {
            return ['sign' => 1, 'hours' => 0, 'minutes' => 0];
        }

        if (str_starts_with($upper, 'UTC') || str_starts_with($upper, 'GMT')) {
            $raw = trim(substr($raw, 3));
            $upper = strtoupper($raw);
            if ($raw === '') {
                return ['sign' => 1, 'hours' => 0, 'minutes' => 0];
            }
        }

        $sign = 1;
        $raw  = ltrim($raw);

        if ($raw === '') {
            return null;
        }

        $firstChar = $raw[0];
        if ($firstChar === '+' || $firstChar === '-') {
            $sign = $firstChar === '-' ? -1 : 1;
            $raw  = substr($raw, 1);
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([' ', '\t'], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        $hours   = null;
        $minutes = null;

        if (str_contains($normalized, ':')) {
            $parts = explode(':', $normalized, 3);
            if (count($parts) < 2) {
                return null;
            }

            $hoursPart   = $parts[0];
            $minutesPart = $parts[1];

            if ($hoursPart === '' || $minutesPart === '') {
                return null;
            }

            if (!ctype_digit($hoursPart) || !ctype_digit($minutesPart)) {
                return null;
            }

            $hours   = (int) $hoursPart;
            $minutes = (int) substr($minutesPart, 0, 2);
        } elseif (preg_match('/^\d+(?:\.\d+)?$/', $normalized) === 1) {
            if (str_contains($normalized, '.')) {
                $floatHours = (float) $normalized;
                $hours      = (int) floor(abs($floatHours));
                $minutes    = (int) round((abs($floatHours) - $hours) * 60);
            } else {
                if (!ctype_digit($normalized)) {
                    return null;
                }

                $length = strlen($normalized);
                if ($length <= 2) {
                    $hours   = (int) $normalized;
                    $minutes = 0;
                } else {
                    $hours   = (int) substr($normalized, 0, $length - 2);
                    $minutes = (int) substr($normalized, -2);
                }
            }
        } else {
            return null;
        }

        if ($hours === null || $minutes === null) {
            return null;
        }

        if ($minutes >= 60) {
            $hours  += (int) floor($minutes / 60);
            $minutes = $minutes % 60;
        }

        if ($hours > 14) {
            return null;
        }

        if ($minutes < 0 || $minutes > 59) {
            return null;
        }

        return [
            'sign'    => $sign,
            'hours'   => $hours,
            'minutes' => $minutes,
        ];
    }
}
