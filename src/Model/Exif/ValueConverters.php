<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function abs;
use function array_map;
use function count;
use function ctype_digit;
use function explode;
use function floor;
use function fmod;
use function iconv;
use function implode;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function ord;
use function preg_match;
use function preg_replace;
use function round;
use function rtrim;
use function sprintf;
use function strpos;
use function unpack;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Helper methods that translate EXIF/TIFF values into PHP friendly scalars.
 *
 * @phpstan-type ExifScalar int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
 * @phpstan-type GpsFieldMap array{
 *     lat_ref:?string,
 *     lat:?float,
 *     lon_ref:?string,
 *     lon:?float,
 *     alt_ref:?int,
 *     alt:?float,
 *     version:?string,
 *     satellites:?string,
 *     status:?string,
 *     measure_mode:?string,
 *     dop:?float,
 *     speed_ref:?string,
 *     speed_ms:?float,
 *     speed_original_ref:?string,
 *     speed_original:?float,
 *     track_ref:?string,
 *     track:?float,
 *     img_direction_ref:?string,
 *     img_direction:?float,
 *     map_datum:?string,
 *     dest_lat_ref:?string,
 *     dest_lat:?float,
 *     dest_lon_ref:?string,
 *     dest_lon:?float,
 *     dest_bearing_ref:?string,
 *     dest_bearing:?float,
 *     dest_distance_ref:?string,
 *     dest_distance_m:?float,
 *     dest_distance_original_ref:?string,
 *     dest_distance_original:?float,
 *     processing_method:?string,
 *     area_information:?string,
 *     date:?string,
 *     time:?string,
 *     timestamp:?DateTimeImmutable,
 *     differential:?int,
 *     h_positioning_error:?float
 * }
 */
final readonly class ValueConverters
{
    private const MAX_SRATIONAL_MATRIX_DIMENSION = 64;
    private const MAX_SRATIONAL_MATRIX_LABEL_LENGTH = 255;
    private const SRATIONAL_VALUE_SIZE = 8;
    private const MAX_PRINT_IMAGE_MATCHING_PARAMETERS = 512;

    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param ExifScalar $v The value to convert.
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
     * Normalises EXIF battery level readings to a percentage.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Raw battery level value.
     */
    public static function batteryLevelToPercent(int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $numeric = self::rationalToFloat($value);
        if ($numeric !== null) {
            return self::normaliseBatteryPercent($numeric);
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/', $normalized, $matches) === 1) {
            $denominator = (float) $matches[2];
            if ($denominator == 0.0) {
                return null;
            }

            return self::normaliseBatteryPercent((float) $matches[1] / $denominator);
        }

        if ($normalized !== '' && $normalized[strlen($normalized) - 1] === '%') {
            $numericPart = rtrim(substr($normalized, 0, -1));
            if ($numericPart === '') {
                return null;
            }

            if (preg_match('/^(-?\d+(?:\.\d+)?)$/', $numericPart, $matches) !== 1) {
                return null;
            }

            return (float) $matches[1];
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)$/', $normalized, $matches) === 1) {
            $numericValue = (float) $matches[1];

            return self::normaliseBatteryPercent($numericValue);
        }

        return null;
    }

    /**
     * Scales ratios to percentages when battery readings are encoded as fractions.
     */
    private static function normaliseBatteryPercent(float $value): float
    {
        if ($value >= -1.0 && $value <= 1.0) {
            return $value * 100.0;
        }

        return $value;
    }

    /**
     * Converts a stored APEX aperture value into a traditional f-number.
     *
     * @param ExifScalar $value The APEX value to convert.
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
     * Converts an APEX shutter speed value into seconds.
     *
     * @param ExifScalar $value The APEX value to convert.
     */
    public static function apexShutterSpeedToSeconds(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?float {
        $apex = self::rationalToFloat($value);

        if ($apex === null && is_string($value) && is_numeric($value)) {
            $apex = (float) $value;
        }

        if ($apex === null) {
            return null;
        }

        return 2 ** (-$apex);
    }

    /**
     * Decodes the spatial frequency response payload as defined by EXIF figure 14.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public static function decodeSpatialFrequencyResponse(?string $payload): ?array
    {
        return self::decodeSrationalMatrix($payload);
    }

    /**
     * Decodes the opto-electronic conversion function payload as defined by EXIF table 15.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public static function decodeOecf(?string $payload): ?array
    {
        return self::decodeSrationalMatrix($payload);
    }

    /**
     * Decodes an EXIF SRATIONAL matrix that contains labelled columns and rows.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    private static function decodeSrationalMatrix(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $length = strlen($payload);
        if ($length < 4) {
            return null;
        }

        $header = unpack('ncolumns/nrows', substr($payload, 0, 4));
        if (!is_array($header)) {
            return null;
        }

        $columns = (int) ($header['columns'] ?? 0);
        $rows    = (int) ($header['rows'] ?? 0);

        if ($columns <= 0 || $rows <= 0) {
            return null;
        }

        if ($columns > self::MAX_SRATIONAL_MATRIX_DIMENSION || $rows > self::MAX_SRATIONAL_MATRIX_DIMENSION) {
            return null;
        }

        if ($columns > intdiv(PHP_INT_MAX, $rows)) {
            return null;
        }

        $offset = 4;
        $columnLabels = [];
        for ($i = 0; $i < $columns; $i++) {
            $labelData = self::consumeSrationalMatrixLabel($payload, $offset, $length);
            if ($labelData === null) {
                return null;
            }

            [$label, $offset] = $labelData;
            $columnLabels[]   = $label;
        }

        $rowLabels = [];
        for ($i = 0; $i < $rows; $i++) {
            $labelData = self::consumeSrationalMatrixLabel($payload, $offset, $length);
            if ($labelData === null) {
                return null;
            }

            [$label, $offset] = $labelData;
            $rowLabels[]      = $label;
        }

        $cells = $columns * $rows;
        if ($cells > intdiv(PHP_INT_MAX, self::SRATIONAL_VALUE_SIZE)) {
            return null;
        }

        $required = $cells * self::SRATIONAL_VALUE_SIZE;
        if ($required > $length - $offset) {
            return null;
        }

        $values = [];
        for ($rowIndex = 0; $rowIndex < $rows; $rowIndex++) {
            $rowValues = [];

            for ($colIndex = 0; $colIndex < $columns; $colIndex++) {
                $numerator = self::readSrationalInt32($payload, $offset, $length);
                $denominator = self::readSrationalInt32($payload, $offset + 4, $length);
                if ($numerator === null || $denominator === null) {
                    return null;
                }

                $offset += self::SRATIONAL_VALUE_SIZE;

                if ($denominator === 0) {
                    $rowValues[] = null;
                    continue;
                }

                $rowValues[] = (float) $numerator / (float) $denominator;
            }

            $values[] = $rowValues;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'labels' => [
                'columns' => $columnLabels,
                'rows' => $rowLabels,
            ],
            'values' => $values,
        ];
    }

    /**
     * Formats a components configuration payload into human readable channel labels.
     *
     * @param array<int, int|float|string>|ExifNumericList|string|int|null $value Raw EXIF value.
     *
     * @return list<string>|null
     */
    public static function componentsConfigurationLabels(array|ExifNumericList|string|int|null $value): ?array
    {
        $components = self::toIntList($value);
        if ($components === null || $components === []) {
            return null;
        }

        $labels = [];
        foreach ($components as $component) {
            $label = match ($component) {
                0       => '-',
                1       => 'Y',
                2       => 'Cb',
                3       => 'Cr',
                4       => 'R',
                5       => 'G',
                6       => 'B',
                7       => 'A',
                default => null,
            };

            if ($label === null) {
                return null;
            }

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * Returns a human readable description for the components configuration.
     *
     * @param array<int, int|float|string>|ExifNumericList|string|int|null $value Raw EXIF value.
     */
    public static function componentsConfigurationDescription(
        array|ExifNumericList|string|int|null $value,
    ): ?string {
        $labels = self::componentsConfigurationLabels($value);

        return $labels !== null ? implode(' ', $labels) : null;
    }

    /**
     * Converts a CFA pattern definition into typed colour enums.
     *
     * @param array<int, int|float|string>|ExifNumericList|string|int|null $value Raw EXIF representation.
     *
     * @return list<CfaPatternColor>|null
     */
    public static function cfaPatternToColors(array|ExifNumericList|string|int|null $value): ?array
    {
        $components = self::toIntList($value);
        if ($components === null || $components === []) {
            return null;
        }

        $colors = [];
        foreach ($components as $component) {
            $color = CfaPatternColor::fromExifValue($component);
            if (!$color instanceof CfaPatternColor) {
                return null;
            }

            $colors[] = $color;
        }

        return $colors;
    }

    /**
     * Converts a GPS speed measurement into metres per second.
     *
     * @param string|null $ref   Speed reference (K, M or N).
     * @param ExifScalar  $value The measured value.
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
            'K'     => $numeric / 3.6,
            'M'     => $numeric * 0.44704,
            'N'     => $numeric * 0.5144444444444444,
            default => null,
        };
    }

    /**
     * Converts a GPS destination distance to metres based on the reference unit.
     *
     * @param string|null $ref   Distance reference (K, M or N).
     * @param ExifScalar  $value The measured value.
     */
    public static function gpsDistanceToMetres(
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
            'K'     => $numeric * 1000.0,
            'M'     => $numeric * 1609.344,
            'N'     => $numeric * 1852.0,
            default => null,
        };
    }

    /**
     * Normalises a compass bearing to the [0, 360) interval.
     */
    public static function normalizeBearing(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $bearing = fmod((float) $value, 360.0);

        if ($bearing < 0.0) {
            $bearing += 360.0;
        }

        if ($bearing < 0.0 || $bearing >= 360.0) {
            $bearing = fmod($bearing, 360.0);

            if ($bearing < 0.0) {
                $bearing += 360.0;
            }
        }

        return $bearing;
    }

    /**
     * Converts the EXIF flash bit field into a typed value object.
     *
     * @param ExifScalar $value Flash tag value representation.
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
     * Returns the default GPS metadata structure with all keys initialised to null.
     *
     * @return GpsFieldMap
     */
    public static function emptyGpsResult(): array
    {
        return [
            'lat_ref'             => null,
            'lat'                 => null,
            'lon_ref'             => null,
            'lon'                 => null,
            'alt_ref'             => null,
            'alt'                 => null,
            'version'             => null,
            'satellites'          => null,
            'status'              => null,
            'measure_mode'        => null,
            'dop'                 => null,
            'speed_ref'           => null,
            'speed_ms'            => null,
            'speed_original_ref'  => null,
            'speed_original'      => null,
            'track_ref'           => null,
            'track'               => null,
            'img_direction_ref'   => null,
            'img_direction'       => null,
            'map_datum'           => null,
            'dest_lat_ref'        => null,
            'dest_lat'            => null,
            'dest_lon_ref'        => null,
            'dest_lon'            => null,
            'dest_bearing_ref'    => null,
            'dest_bearing'        => null,
            'dest_distance_ref'   => null,
            'dest_distance_m'     => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
            'processing_method'   => null,
            'area_information'    => null,
            'date'                => null,
            'time'                => null,
            'timestamp'           => null,
            'differential'        => null,
            'h_positioning_error' => null,
        ];
    }

    /**
     * Extracts GPS metadata including position, navigation and timing information from an IFD.
     *
     * @param Ifd $gps The GPS IFD containing coordinate tags.
     *
     * @return GpsFieldMap
     */
    public static function gpsFromIfd(Ifd $gps): array
    {
        $result = self::emptyGpsResult();

        $latRefEntry = $gps->get(ExifTag::GPS_LATITUDE_REF);
        $latValEntry = $gps->get(ExifTag::GPS_LATITUDE);
        $lonRefEntry = $gps->get(ExifTag::GPS_LONGITUDE_REF);
        $lonValEntry = $gps->get(ExifTag::GPS_LONGITUDE);

        $latRef = $latRefEntry?->value;
        $latVal = $latValEntry?->value;
        $lonRef = $lonRefEntry?->value;
        $lonVal = $lonValEntry?->value;

        $result['lat_ref'] = is_string($latRef) ? strtoupper(trim($latRef)) : null;
        $result['lon_ref'] = is_string($lonRef) ? strtoupper(trim($lonRef)) : null;

        $latPairs = $latVal instanceof ExifRationalList ? $latVal : null;
        $lonPairs = $lonVal instanceof ExifRationalList ? $lonVal : null;

        $result['lat'] = self::dmsToFloat($result['lat_ref'], $latPairs);
        $result['lon'] = self::dmsToFloat($result['lon_ref'], $lonPairs);

        $altRefEntry = $gps->get(ExifTag::GPS_ALTITUDE_REF);
        $altRefValue = $altRefEntry?->value;
        if ($altRefValue instanceof ExifNumericList) {
            $altRefValue = $altRefValue->values[0] ?? null;
        }

        if (is_int($altRefValue)) {
            $result['alt_ref'] = $altRefValue;
        } elseif (is_float($altRefValue)) {
            $result['alt_ref'] = (int) round($altRefValue);
        }

        $altEntry = $gps->get(ExifTag::GPS_ALTITUDE);
        if ($altEntry instanceof IfdEntry && $altEntry->value instanceof ExifRational) {
            $alt = self::rationalToFloat($altEntry->value);

            if ($alt !== null && $result['alt_ref'] === 1) {
                $alt = -$alt;
            }

            $result['alt'] = $alt;
        }

        $versionEntry     = $gps->get(ExifTag::GPS_VERSION_ID);
        $satellitesEntry  = $gps->get(ExifTag::GPS_SATELLITES);
        $statusEntry      = $gps->get(ExifTag::GPS_STATUS);
        $measureEntry     = $gps->get(ExifTag::GPS_MEASURE_MODE);
        $dopEntry         = $gps->get(ExifTag::GPS_DOP);
        $speedRefEntry    = $gps->get(ExifTag::GPS_SPEED_REF);
        $speedEntry       = $gps->get(ExifTag::GPS_SPEED);
        $trackRefEntry    = $gps->get(ExifTag::GPS_TRACK_REF);
        $trackEntry       = $gps->get(ExifTag::GPS_TRACK);
        $imgDirRefEntry   = $gps->get(ExifTag::GPS_IMG_DIRECTION_REF);
        $imgDirEntry      = $gps->get(ExifTag::GPS_IMG_DIRECTION);
        $mapDatumEntry    = $gps->get(ExifTag::GPS_MAP_DATUM);
        $destLatRefEntry  = $gps->get(ExifTag::GPS_DEST_LATITUDE_REF);
        $destLatEntry     = $gps->get(ExifTag::GPS_DEST_LATITUDE);
        $destLonRefEntry  = $gps->get(ExifTag::GPS_DEST_LONGITUDE_REF);
        $destLonEntry     = $gps->get(ExifTag::GPS_DEST_LONGITUDE);
        $destBearRefEntry = $gps->get(ExifTag::GPS_DEST_BEARING_REF);
        $destBearEntry    = $gps->get(ExifTag::GPS_DEST_BEARING);
        $destDistRefEntry = $gps->get(ExifTag::GPS_DEST_DISTANCE_REF);
        $destDistEntry    = $gps->get(ExifTag::GPS_DEST_DISTANCE);
        $processEntry     = $gps->get(ExifTag::GPS_PROCESSING_METHOD);
        $areaEntry        = $gps->get(ExifTag::GPS_AREA_INFORMATION);
        $dateEntry        = $gps->get(ExifTag::GPS_DATE_STAMP);
        $timeEntry        = $gps->get(ExifTag::GPS_TIME_STAMP);

        $versionParts            = self::formatGpsVersion($versionEntry?->value);
        $result['version']      = $versionParts['normalized'];
        $result['version_raw']  = $versionParts['raw'];
        $result['satellites']   = self::sanitizeString($satellitesEntry?->value);
        $result['status']       = self::sanitizeString($statusEntry?->value);
        $result['measure_mode'] = self::sanitizeString($measureEntry?->value);
        $result['dop']          = self::rationalToFloat($dopEntry?->value);

        $speedRefValue                = $speedRefEntry?->value;
        $speedOriginalRef             = self::sanitizeString($speedRefValue);
        $speedRef                     = is_string($speedRefValue) ? strtoupper(trim($speedRefValue)) : null;
        $result['speed_ref']          = $speedRef;
        $result['speed_ms']           = self::gpsSpeedToMs($speedRef, $speedEntry?->value);
        $result['speed_original_ref'] = $speedOriginalRef;
        $result['speed_original']     = self::rationalToFloat($speedEntry?->value);

        $trackRefValue       = $trackRefEntry?->value;
        $result['track_ref'] = is_string($trackRefValue) ? strtoupper(trim($trackRefValue)) : null;
        $trackValue          = self::rationalToFloat($trackEntry?->value);
        $result['track']     = self::normalizeBearing($trackValue);

        $imgDirRefValue              = $imgDirRefEntry?->value;
        $result['img_direction_ref'] = is_string($imgDirRefValue) ? strtoupper(trim($imgDirRefValue)) : null;
        $imgDirectionValue           = self::rationalToFloat($imgDirEntry?->value);
        $result['img_direction']     = self::normalizeBearing($imgDirectionValue);

        $result['map_datum'] = self::sanitizeString($mapDatumEntry?->value);

        $destLatRefValue        = $destLatRefEntry?->value;
        $destLatVal             = $destLatEntry?->value;
        $destLatPairs           = $destLatVal instanceof ExifRationalList ? $destLatVal : null;
        $result['dest_lat_ref'] = is_string($destLatRefValue) ? strtoupper(trim($destLatRefValue)) : null;
        $result['dest_lat']     = self::dmsToFloat($result['dest_lat_ref'], $destLatPairs);

        $destLonRefValue        = $destLonRefEntry?->value;
        $destLonVal             = $destLonEntry?->value;
        $destLonPairs           = $destLonVal instanceof ExifRationalList ? $destLonVal : null;
        $result['dest_lon_ref'] = is_string($destLonRefValue) ? strtoupper(trim($destLonRefValue)) : null;
        $result['dest_lon']     = self::dmsToFloat($result['dest_lon_ref'], $destLonPairs);

        $destBearingRefValue        = $destBearRefEntry?->value;
        $result['dest_bearing_ref'] = is_string($destBearingRefValue) ? strtoupper(trim($destBearingRefValue)) : null;
        $destBearingValue           = self::rationalToFloat($destBearEntry?->value);
        $result['dest_bearing']     = self::normalizeBearing($destBearingValue);

        $destDistanceRefValue                 = $destDistRefEntry?->value;
        $result['dest_distance_ref']            = is_string($destDistanceRefValue) ? strtoupper(trim($destDistanceRefValue)) : null;
        $result['dest_distance_original_ref']   = self::sanitizeString($destDistanceRefValue);
        $result['dest_distance_original']       = self::rationalToFloat($destDistEntry?->value);
        $result['dest_distance_m']              = self::gpsDistanceToMetres($result['dest_distance_ref'], $destDistEntry?->value);

        $result['processing_method'] = self::decodeUndefinedString($processEntry?->value);
        $result['area_information']  = self::decodeUndefinedString($areaEntry?->value);

        $dateParts       = self::normalizeGpsDate($dateEntry?->value);
        $result['date'] = $dateParts['normalized'];
        $result['date_raw'] = $dateParts['raw'];
        $timeParts      = $timeEntry instanceof IfdEntry && $timeEntry->value instanceof ExifRationalList
            ? self::parseGpsTime($timeEntry->value)
            : null;
        $result['time']      = self::formatGpsTime($timeParts);
        $result['timestamp'] = self::combineGpsDateTime($result['date'], $timeParts);

        $diffEntry = $gps->get(ExifTag::GPS_DIFFERENTIAL);
        $diffValue = $diffEntry?->value;
        if ($diffValue instanceof ExifNumericList) {
            $diffValue = $diffValue->values[0] ?? null;
        }

        if (is_int($diffValue)) {
            $result['differential'] = $diffValue;
        } elseif (is_float($diffValue)) {
            $result['differential'] = (int) round($diffValue);
        }

        $hPositionEntry                = $gps->get(ExifTag::GPS_H_POSITIONING_ERROR);
        $result['h_positioning_error'] = self::rationalToFloat($hPositionEntry?->value);

        return $result;
    }

    /**
     * Decodes the Epson Print Image Matching parameter block stored in tag 0xC4A5.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{header:string, version:string, parameters:list<array{id:int, value:int}>}|null
     */
    public static function decodePrintImageMatching(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $length = strlen($payload);
        if ($length < 14) {
            return null;
        }

        $header = substr($payload, 0, 8);
        if (!str_starts_with($header, 'PrintIM')) {
            return null;
        }

        $versionRaw = substr($payload, 8, 4);
        $countBytes = substr($payload, 12, 2);

        if ($countBytes === false || strlen($countBytes) !== 2) {
            return null;
        }

        $countData = unpack('ncount', $countBytes);
        if (!is_array($countData)) {
            return null;
        }

        $count = (int) ($countData['count'] ?? 0);
        if ($count < 0 || $count > self::MAX_PRINT_IMAGE_MATCHING_PARAMETERS) {
            return null;
        }

        $required = 14 + ($count * 6);
        if ($required > $length) {
            return null;
        }

        $parameters = [];
        $offset     = 14;
        for ($i = 0; $i < $count; ++$i) {
            if ($offset + 6 > $length) {
                return null;
            }

            $entryData = substr($payload, $offset, 6);
            if ($entryData === false || strlen($entryData) !== 6) {
                return null;
            }

            $entry = unpack('nid/Nvalue', $entryData);
            if (!is_array($entry) || !isset($entry['id'], $entry['value'])) {
                return null;
            }

            $parameters[] = [
                'id'    => (int) $entry['id'],
                'value' => (int) $entry['value'],
            ];

            $offset += 6;
        }

        return [
            'header'     => rtrim($header, "\0"),
            'version'    => rtrim($versionRaw, "\0"),
            'parameters' => $parameters,
        ];
    }

    /**
     * Extracts a null-terminated label from the SRATIONAL matrix payload.
     *
     * @return array{0:string,1:int}|null
     */
    private static function consumeSrationalMatrixLabel(string $payload, int $offset, int $length): ?array
    {
        if ($offset >= $length) {
            return null;
        }

        $end = strpos($payload, "\0", $offset);
        if ($end === false) {
            return null;
        }

        $labelLength = $end - $offset;
        if ($labelLength < 0 || $labelLength > self::MAX_SRATIONAL_MATRIX_LABEL_LENGTH) {
            return null;
        }

        $label  = trim(substr($payload, $offset, $labelLength));
        $offset = $end + 1;

        return [$label, $offset];
    }

    /**
     * Reads a signed 32-bit integer from the SRATIONAL matrix payload.
     */
    private static function readSrationalInt32(string $payload, int $offset, int $length): ?int
    {
        if ($offset + 4 > $length) {
            return null;
        }

        $value = unpack('N', substr($payload, $offset, 4));
        if (!is_array($value)) {
            return null;
        }

        $int = (int) $value[1];
        if ($int >= 0x80000000) {
            $int -= 0x100000000;
        }

        return $int;
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
     * Converts a GPS version payload into a dotted string.
     *
     * @param ExifScalar $value Raw value extracted from the IFD entry.
     *
     * @return array{normalized: ?string, raw: ?string}
     */
    private static function formatGpsVersion(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): array {
        $raw = is_string($value) ? $value : null;
        $normalized = null;

        if ($value instanceof ExifNumericList) {
            $components = array_map(static fn (int|float $component): int => (int) $component, $value->values);

            $normalized = implode('.', $components);

            return [
                'normalized' => $normalized,
                'raw' => $raw,
            ];
        }

        if (is_string($value)) {
            $clean = trim(str_replace("\0", '', $value));
            if ($clean !== '') {
                $normalized = $clean;
            }

            return [
                'normalized' => $normalized,
                'raw' => $raw,
            ];
        }

        if (is_int($value)) {
            $normalized = (string) $value;

            return [
                'normalized' => $normalized,
                'raw' => null,
            ];
        }

        if (is_float($value)) {
            $normalized = (string) $value;

            return [
                'normalized' => $normalized,
                'raw' => null,
            ];
        }

        return [
            'normalized' => null,
            'raw' => $raw,
        ];
    }

    /**
     * Normalises ASCII-like EXIF strings by trimming whitespace and null padding.
     *
     * @param ExifScalar $value Raw value extracted from the IFD entry.
     */
    private static function sanitizeString(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim(str_replace("\0", '', $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Decodes undefined GPS ASCII strings with optional encoding prefixes.
     *
     * @param ExifScalar $value Raw value extracted from the IFD entry.
     */
    private static function decodeUndefinedString(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $payload  = $value;
        $encoding = null;

        $prefixes = [
            "ASCII\0\0\0"    => 'ASCII',
            "UNICODE\0"        => 'UNICODE',
            "JIS\0\0\0\0\0" => 'JIS',
        ];

        foreach ($prefixes as $prefix => $label) {
            if (str_starts_with($payload, $prefix)) {
                $payload  = substr($payload, strlen($prefix));
                $encoding = $label;
                break;
            }
        }

        return match ($encoding) {
            'UNICODE' => self::decodeUndefinedUnicode($payload),
            'JIS'     => self::decodeUndefinedJis($payload),
            default   => self::sanitizeString($payload),
        };
    }

    /**
     * Decodes a UTF-16 encoded undefined GPS string into UTF-8.
     */
    private static function decodeUndefinedUnicode(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $converted = @iconv('UTF-16LE', 'UTF-8', $payload);
        if ($converted === false) {
            $converted = @iconv('UTF-16BE', 'UTF-8', $payload);
        }

        if ($converted !== false) {
            return self::sanitizeString($converted);
        }

        $stripped = preg_replace('/\x00/u', '', $payload);
        if ($stripped === null) {
            return null;
        }

        return self::sanitizeString($stripped);
    }

    /**
     * Decodes a Shift-JIS encoded undefined GPS string into UTF-8.
     */
    private static function decodeUndefinedJis(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $converted = @iconv('SJIS', 'UTF-8', $payload);
        if ($converted !== false) {
            return self::sanitizeString($converted);
        }

        return self::sanitizeString($payload);
    }

    /**
     * Normalises a GPS date stamp into an ISO 8601 calendar date.
     *
     * @param ExifScalar $value Raw value extracted from the IFD entry.
     *
     * @return array{normalized: ?string, raw: ?string}
     */
    private static function normalizeGpsDate(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): array {
        $raw = is_string($value) ? $value : null;
        if (!is_string($value)) {
            return [
                'normalized' => null,
                'raw' => $raw,
            ];
        }

        $clean = trim(str_replace("\0", '', $value));
        if ($clean === '') {
            return [
                'normalized' => null,
                'raw' => $raw,
            ];
        }

        if (preg_match('/^\d{4}:\d{2}:\d{2}$/', $clean) !== 1) {
            return [
                'normalized' => null,
                'raw' => $raw,
            ];
        }

        return [
            'normalized' => str_replace(':', '-', $clean),
            'raw' => $raw,
        ];
    }

    /**
     * Extracts hour, minute and second components from a GPS time stamp list.
     *
     * @return array{hours:int, minutes:int, seconds:float}|null
     */
    private static function parseGpsTime(ExifRationalList $value): ?array
    {
        if (count($value->values) < 3) {
            return null;
        }

        $hours   = self::rationalToFloat($value->values[0]);
        $minutes = self::rationalToFloat($value->values[1]);
        $seconds = self::rationalToFloat($value->values[2]);

        if ($hours === null || $minutes === null || $seconds === null) {
            return null;
        }

        return [
            'hours'   => (int) floor($hours),
            'minutes' => (int) floor($minutes),
            'seconds' => $seconds,
        ];
    }

    /**
     * Formats GPS time components into a human readable HH:MM:SS(.ffffff) string.
     *
     * @param array{hours:int, minutes:int, seconds:float}|null $timeParts
     */
    private static function formatGpsTime(?array $timeParts): ?string
    {
        if ($timeParts === null) {
            return null;
        }

        $secondsFloat = $timeParts['seconds'];
        $secondsInt   = (int) floor($secondsFloat);
        $fraction     = $secondsFloat - $secondsInt;
        $microseconds = (int) round($fraction * 1_000_000);

        if ($microseconds >= 1_000_000) {
            ++$secondsInt;
            $microseconds -= 1_000_000;
        }

        $time = sprintf('%02d:%02d:%02d', $timeParts['hours'], $timeParts['minutes'], $secondsInt);

        if ($microseconds > 0) {
            $micro = rtrim(sprintf('%06d', $microseconds), '0');
            if ($micro === '') {
                $micro = '0';
            }

            $time .= '.' . $micro;
        }

        return $time;
    }

    /**
     * Combines a GPS date and time into a UTC timestamp.
     *
     * @param array{hours:int, minutes:int, seconds:float}|null $timeParts
     */
    private static function combineGpsDateTime(?string $date, ?array $timeParts): ?DateTimeImmutable
    {
        if ($date === null || $timeParts === null) {
            return null;
        }

        $secondsFloat = $timeParts['seconds'];
        $secondsInt   = (int) floor($secondsFloat);
        $fraction     = $secondsFloat - $secondsInt;
        $microseconds = (int) round($fraction * 1_000_000);

        if ($microseconds >= 1_000_000) {
            ++$secondsInt;
            $microseconds -= 1_000_000;
        }

        $timeString = sprintf('%02d:%02d:%02d', $timeParts['hours'], $timeParts['minutes'], $secondsInt);
        $format     = 'Y-m-d H:i:s';

        if ($microseconds > 0) {
            $timeString .= sprintf('.%06d', $microseconds);
            $format .= '.u';
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            $format,
            $date . ' ' . $timeString,
            new DateTimeZone('UTC'),
        );

        if ($dateTime === false) {
            return null;
        }

        return $dateTime;
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
        $raw = str_replace(['−', '–', '—'], '-', $raw);
        $raw = str_replace(['＋'], '+', $raw);

        if ($raw === '') {
            return null;
        }

        $upper = strtoupper($raw);
        if (in_array($upper, ['Z', 'UTC', 'GMT'], true)) {
            return ['sign' => 1, 'hours' => 0, 'minutes' => 0];
        }

        if (str_starts_with($upper, 'UTC') || str_starts_with($upper, 'GMT')) {
            $raw   = trim(substr($raw, 3));
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

        if ($minutes < 0) {
            return null;
        }

        if ($minutes >= 60) {
            $hours += (int) floor($minutes / 60);
            $minutes %= 60;
        }

        if ($hours > 14) {
            return null;
        }

        return [
            'sign'    => $sign,
            'hours'   => $hours,
            'minutes' => $minutes,
        ];
    }

    /**
     * Normalises numeric EXIF representations into a list of integers.
     *
     * @param array<int, int|float|string>|ExifNumericList|string|int|null $value Raw EXIF value representation.
     *
     * @return list<int>|null
     */
    private static function toIntList(array|ExifNumericList|string|int|null $value): ?array
    {
        if ($value instanceof ExifNumericList) {
            if ($value->values === []) {
                return null;
            }

            return array_map(static fn (int|float $component): int => (int) $component, $value->values);
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $ints = [];
            foreach ($value as $component) {
                if (!is_numeric($component)) {
                    return null;
                }

                $ints[] = (int) $component;
            }

            return $ints;
        }

        if (is_int($value)) {
            return [$value];
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $length = strlen($value);
        $ints   = [];
        for ($i = 0; $i < $length; ++$i) {
            $ints[] = ord($value[$i]);
        }

        return $ints;
    }
}
