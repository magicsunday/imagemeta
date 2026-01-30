<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;

use function abs;
use function count;
use function floor;
use function iconv;
use function implode;
use function is_callable;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function preg_replace;
use function round;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Converts GPS-related EXIF values.
 *
 * EXIF 3.0 §4.6.7 and §4.6.8 define the GPS IFD and its coordinate encoding.
 *
 * @phpstan-type GpsFieldMap array{
 *     lat_ref:?string,
 *     lat:?float,
 *     lon_ref:?string,
 *     lon:?float,
 *     alt_ref:?int,
 *     alt:?float,
 *     version:?string,
 *     version_raw:?string,
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
 *     date_raw:?string,
 *     time:?string,
 *     timestamp:?DateTimeImmutable,
 *     differential:?int,
 *     h_positioning_error:?float
 * }
 */
final readonly class GpsConverter
{
    /**
     * EXIF 3.0 §4.6.7.1.1 (GPSVersionID) default value when the field is blank.
     */
    private const string DEFAULT_GPS_VERSION = '2.4.0.0';

    /**
     * Creates the converter with its numeric, string, and rational dependencies.
     *
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     * @param StringConverter   $stringConverter   Dependency for string conversions.
     * @param NumericConverter  $numericConverter  Dependency for numeric conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
        private StringConverter $stringConverter,
        private NumericConverter $numericConverter,
    ) {
    }

    /**
     * Returns the default GPS metadata structure with all keys initialised to null.
     *
     * @return GpsFieldMap
     */
    public function emptyGpsResult(): array
    {
        return [
            'lat_ref'                    => null,
            'lat'                        => null,
            'lon_ref'                    => null,
            'lon'                        => null,
            'alt_ref'                    => null,
            'alt'                        => null,
            'version'                    => null,
            'version_raw'                => null,
            'satellites'                 => null,
            'status'                     => null,
            'measure_mode'               => null,
            'dop'                        => null,
            'speed_ref'                  => null,
            'speed_ms'                   => null,
            'speed_original_ref'         => null,
            'speed_original'             => null,
            'track_ref'                  => null,
            'track'                      => null,
            'img_direction_ref'          => null,
            'img_direction'              => null,
            'map_datum'                  => null,
            'dest_lat_ref'               => null,
            'dest_lat'                   => null,
            'dest_lon_ref'               => null,
            'dest_lon'                   => null,
            'dest_bearing_ref'           => null,
            'dest_bearing'               => null,
            'dest_distance_ref'          => null,
            'dest_distance_m'            => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
            'processing_method'          => null,
            'area_information'           => null,
            'date'                       => null,
            'date_raw'                   => null,
            'time'                       => null,
            'timestamp'                  => null,
            'differential'               => null,
            'h_positioning_error'        => null,
        ];
    }

    /**
     * Extracts GPS metadata including position, navigation and timing information from an IFD.
     *
     * EXIF 3.0 §4.6.8 defines the GPS tag catalogue; the reader keeps the
     * field mapping while honouring the 3.0 clarifications around default values.
     *
     * @param Ifd $gps The GPS IFD containing coordinate tags.
     *
     * @return GpsFieldMap
     */
    public function fromIfd(Ifd $gps): array
    {
        $result = $this->emptyGpsResult();

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

        $latPairs = $this->resolveCoordinatePairs($latVal);
        $lonPairs = $this->resolveCoordinatePairs($lonVal);

        $result['lat'] = $this->dmsToFloat($result['lat_ref'], $latPairs);
        $result['lon'] = $this->dmsToFloat($result['lon_ref'], $lonPairs);

        $altRefEntry = $gps->get(ExifTag::GPS_ALTITUDE_REF);
        $altRefValue = $altRefEntry?->value;
        $altRef      = $this->normaliseAltitudeRef($altRefValue);
        if ($altRef !== null) {
            $result['alt_ref'] = $altRef;
        }

        $altEntry = $gps->get(ExifTag::GPS_ALTITUDE);
        if ($altEntry instanceof IfdEntry) {
            $alt = $this->rationalConverter->toFloat($altEntry->value);

            // EXIF 3.0 §4.6.7.1.6: Values 1 (below ellipsoidal) and 3 (below sea level) indicate negative altitude
            if ($alt !== null && ($result['alt_ref'] === 1 || $result['alt_ref'] === 3)) {
                $alt = -$alt;
            }

            if ($alt !== null) {
                $result['alt'] = $alt;
            }
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

        $versionParts           = $this->formatVersion($versionEntry?->value);
        $result['version']      = $versionParts['normalized'];
        $result['version_raw']  = $versionParts['raw'];
        $result['satellites']   = $this->stringConverter->sanitize($satellitesEntry?->value);
        $result['status']       = $this->stringConverter->sanitize($statusEntry?->value);
        $result['measure_mode'] = $this->stringConverter->sanitize($measureEntry?->value);
        $result['dop']          = $this->rationalConverter->toFloat($dopEntry?->value);

        $speedRefValue                = $speedRefEntry?->value;
        $speedOriginalRef             = $this->stringConverter->sanitize($speedRefValue);
        $speedRef                     = is_string($speedRefValue) ? strtoupper(trim($speedRefValue)) : null;
        $result['speed_ref']          = $speedRef;
        $result['speed_ms']           = $this->speedToMs($speedRef, $speedEntry?->value);
        $result['speed_original_ref'] = $speedOriginalRef;
        $result['speed_original']     = $this->rationalConverter->toFloat($speedEntry?->value);

        $trackRefValue       = $trackRefEntry?->value;
        $result['track_ref'] = is_string($trackRefValue) ? strtoupper(trim($trackRefValue)) : null;
        $trackValue          = $this->rationalConverter->toFloat($trackEntry?->value);
        $result['track']     = $this->normalizeBearing($trackValue);

        $imgDirRefValue              = $imgDirRefEntry?->value;
        $result['img_direction_ref'] = is_string($imgDirRefValue) ? strtoupper(trim($imgDirRefValue)) : null;
        $imgDirectionValue           = $this->rationalConverter->toFloat($imgDirEntry?->value);
        $result['img_direction']     = $this->normalizeBearing($imgDirectionValue);

        $result['map_datum'] = $this->stringConverter->sanitize($mapDatumEntry?->value);

        $destLatRefValue        = $destLatRefEntry?->value;
        $destLatVal             = $destLatEntry?->value;
        $destLatPairs           = $destLatVal instanceof ExifRationalList ? $destLatVal : null;
        $result['dest_lat_ref'] = is_string($destLatRefValue) ? strtoupper(trim($destLatRefValue)) : null;
        $result['dest_lat']     = $this->dmsToFloat($result['dest_lat_ref'], $destLatPairs);

        $destLonRefValue        = $destLonRefEntry?->value;
        $destLonVal             = $destLonEntry?->value;
        $destLonPairs           = $destLonVal instanceof ExifRationalList ? $destLonVal : null;
        $result['dest_lon_ref'] = is_string($destLonRefValue) ? strtoupper(trim($destLonRefValue)) : null;
        $result['dest_lon']     = $this->dmsToFloat($result['dest_lon_ref'], $destLonPairs);

        $destBearingRefValue        = $destBearRefEntry?->value;
        $result['dest_bearing_ref'] = is_string($destBearingRefValue) ? strtoupper(trim($destBearingRefValue)) : null;
        $destBearingValue           = $this->rationalConverter->toFloat($destBearEntry?->value);
        $result['dest_bearing']     = $this->normalizeBearing($destBearingValue);

        $destDistanceRefValue                 = $destDistRefEntry?->value;
        $result['dest_distance_ref']          = is_string($destDistanceRefValue) ? strtoupper(trim($destDistanceRefValue)) : null;
        $result['dest_distance_original_ref'] = $this->stringConverter->sanitize($destDistanceRefValue);
        $result['dest_distance_original']     = $this->rationalConverter->toFloat($destDistEntry?->value);
        $result['dest_distance_m']            = $this->distanceToMetres($result['dest_distance_ref'], $destDistEntry?->value);

        $result['processing_method'] = $this->decodeUndefinedString($processEntry?->value);
        $result['area_information']  = $this->decodeUndefinedString($areaEntry?->value);

        $dateParts          = $this->normalizeDate($dateEntry?->value);
        $result['date']     = $dateParts['normalized'];
        $result['date_raw'] = $dateParts['raw'];
        $timeParts          = $timeEntry instanceof IfdEntry && $timeEntry->value instanceof ExifRationalList
            ? $this->parseTime($timeEntry->value)
            : null;
        $result['time']      = $this->formatTime($timeParts);
        $result['timestamp'] = $this->combineDateTime($result['date'], $timeParts);

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
        $result['h_positioning_error'] = $this->rationalConverter->toFloat($hPositionEntry?->value);

        return $result;
    }

    /**
     * Converts a GPS speed measurement into metres per second.
     *
     * EXIF 3.0 §4.6.8 (GPSSpeedRef/GPSSpeed) defines the unit codes K, M and N.
     *
     * @param string|null                                                                $ref   Speed reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public function speedToMs(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->convertReferencedValue($ref, $value, [
            'K' => static fn (float $numeric): float => $numeric / 3.6,
            'M' => static fn (float $numeric): float => $numeric * 0.44704,
            'N' => static fn (float $numeric): float => $numeric * 0.5144444444444444,
        ]);
    }

    /**
     * Converts a GPS destination distance to metres based on the reference unit.
     *
     * EXIF 3.0 §4.6.8 (GPSDestDistanceRef/GPSDestDistance): nautical miles, statute miles and
     * kilometres resolve to metres here.
     *
     * @param string|null                                                                $ref   Distance reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public function distanceToMetres(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->convertReferencedValue($ref, $value, [
            'K' => static fn (float $numeric): float => $numeric * 1000.0,
            'M' => static fn (float $numeric): float => $numeric * 1609.344,
            'N' => static fn (float $numeric): float => $numeric * 1852.0,
        ]);
    }

    /**
     * Normalises a compass bearing to the [0, 360) interval.
     */
    public function normalizeBearing(int|float|null $value): ?float
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
     * Normalises a numeric GPS value and its reference string.
     *
     * @param string|null                                                                $ref   Reference string.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw numeric value.
     *
     * @return array{ref:string, value:float}|null Normalised reference/value pair or null.
     */
    private function resolveNumericReference(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?array {
        if (!is_string($ref)) {
            return null;
        }

        $numeric = $this->rationalConverter->toFloat($value);
        if ($numeric === null) {
            return null;
        }

        $normalizedRef = strtoupper(trim($ref));
        if ($normalizedRef === '') {
            return null;
        }

        return [
            'ref'   => $normalizedRef,
            'value' => $numeric,
        ];
    }

    /**
     * Converts a referenced numeric value using a unit conversion map.
     *
     * @param string|null                                                                $ref         Reference unit.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value       Raw value.
     * @param array<string, callable(float): float>                                      $conversions Unit conversion callbacks.
     *
     * @return float|null Converted value or null when conversion fails.
     */
    private function convertReferencedValue(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
        array $conversions,
    ): ?float {
        $resolved = $this->resolveNumericReference($ref, $value);
        if ($resolved === null) {
            return null;
        }

        $conversion = $conversions[$resolved['ref']] ?? null;
        if (!is_callable($conversion)) {
            return null;
        }

        return $conversion($resolved['value']);
    }

    /**
     * Converts degrees/minutes/seconds to a decimal float.
     *
     * EXIF 3.0 §4.6.8 states that GPSLatitude/GPSLongitude are SRATIONAL triplets ordered as
     * degrees, minutes and seconds.
     *
     * @param string|null                           $ref Reference direction (N, S, E, W).
     * @param ExifRationalList|ExifNumericList|null $val Coordinate values as DMS.
     */
    public function dmsToFloat(?string $ref, ExifRationalList|ExifNumericList|null $val): ?float
    {
        if (!is_string($ref) || $val === null) {
            return null;
        }

        $components = [];

        if ($val instanceof ExifRationalList) {
            foreach ($val->values as $index => $component) {
                if ($index >= 3) {
                    break;
                }

                $numeric = $this->rationalConverter->toFloat($component);
                if ($numeric === null) {
                    return null;
                }

                $components[] = abs($numeric);
            }
        } else {
            foreach ($val->values as $index => $component) {
                if ($index >= 3) {
                    break;
                }

                $numeric = $this->numericConverter->normaliseComponent($component);
                if ($numeric === null) {
                    return null;
                }

                $components[] = abs($numeric);
            }
        }

        if ($components === []) {
            return null;
        }

        $deg = $components[0];
        $min = $components[1] ?? 0.0;
        $sec = $components[2] ?? 0.0;

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }

    /**
     * Normalises the GPS altitude reference into a valid EXIF 3.0 §4.6.7.1.6 value.
     *
     * @return int|null 0-3 per EXIF 3.0 specification, null when unknown.
     */
    public function normaliseAltitudeRef(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?int {
        if ($value instanceof ExifNumericList) {
            $component = $value->values[0] ?? null;

            return $this->normaliseAltitudeRef($component);
        }

        if ($value instanceof ExifRationalList) {
            $component = $value->values[0] ?? null;

            return $component instanceof ExifRational
                ? $this->normaliseAltitudeRef($component)
                : null;
        }

        if ($value instanceof ExifRational) {
            $numeric = $this->rationalConverter->toFloat($value);

            return $numeric === null ? null : $this->normaliseAltitudeRef($numeric);
        }

        if (is_string($value)) {
            $clean = trim($value);
            if ($clean === '' || !is_numeric($clean)) {
                return null;
            }

            return $this->normaliseAltitudeRef((float) $clean);
        }

        if (is_int($value) || is_float($value)) {
            $normalized = (int) round((float) $value);

            // EXIF 3.0 §4.6.7.1.6: Valid values are 0-3
            if ($normalized < 0 || $normalized > 3) {
                return null;
            }

            return $normalized;
        }

        return null;
    }

    /**
     * Converts a GPS version payload into a dotted string.
     *
     * EXIF 3.0 §4.6.7.1.1 clarifies that an empty GPSVersionID must be treated as 2.4.0.0.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return array{normalized: ?string, raw: ?string}
     */
    public function formatVersion(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): array {
        if ($value instanceof UInt64) {
            $value = $value->fitsSignedInt() ? $value->toInt('GPSVersionID') : null;
        }

        $raw = is_string($value) ? $value : null;

        if ($value instanceof ExifNumericList) {
            $components = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $components[] = $component->toInt('GPSVersionID component');
                } else {
                    $components[] = (int) $component;
                }
            }

            $normalized = implode('.', $components);
            if ($normalized === '') {
                $normalized = self::DEFAULT_GPS_VERSION;
            }

            return [
                'normalized' => $normalized,
                'raw'        => $raw,
            ];
        }

        if (is_string($value)) {
            $clean = trim(str_replace("\0", '', $value));
            if ($clean !== '') {
                return [
                    'normalized' => $clean,
                    'raw'        => $raw,
                ];
            }

            return [
                'normalized' => self::DEFAULT_GPS_VERSION,
                'raw'        => $raw,
            ];
        }

        if (is_int($value)) {
            return [
                'normalized' => (string) $value,
                'raw'        => null,
            ];
        }

        if (is_float($value)) {
            return [
                'normalized' => (string) $value,
                'raw'        => null,
            ];
        }

        return [
            'normalized' => self::DEFAULT_GPS_VERSION,
            'raw'        => $raw,
        ];
    }

    /**
     * Normalises a GPS date stamp into an ISO 8601 calendar date.
     *
     * EXIF 3.0 §4.6.8 (GPSDateStamp): the value is a "YYYY:MM:DD" ASCII string in UTC.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return array{normalized: ?string, raw: ?string}
     */
    public function normalizeDate(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): array {
        $raw = is_string($value) ? $value : null;
        if (!is_string($value)) {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        $clean = trim(str_replace("\0", '', $value));
        if ($clean === '') {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        if (preg_match('/^\d{4}:\d{2}:\d{2}$/', $clean) !== 1) {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        return [
            'normalized' => str_replace(':', '-', $clean),
            'raw'        => $raw,
        ];
    }

    /**
     * Extracts hour, minute and second components from a GPS time stamp list.
     *
     * EXIF 3.0 §4.6.8 (GPSTimeStamp): a three-element rational list representing UTC hours,
     * minutes and seconds.
     *
     * @return array{hours:int, minutes:int, seconds:float}|null
     */
    public function parseTime(ExifRationalList $value): ?array
    {
        if (count($value->values) < 3) {
            return null;
        }

        $hours   = $this->rationalConverter->toFloat($value->values[0]);
        $minutes = $this->rationalConverter->toFloat($value->values[1]);
        $seconds = $this->rationalConverter->toFloat($value->values[2]);

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
    public function formatTime(?array $timeParts): ?string
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
     * @param string|null                                       $date
     * @param array{hours:int, minutes:int, seconds:float}|null $timeParts
     */
    public function combineDateTime(?string $date, ?array $timeParts): ?DateTimeImmutable
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
     * Resolves EXIF GPS degrees/minutes/seconds into a numeric list.
     *
     * @param int|float|string|UInt64|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @return ExifRationalList|ExifNumericList|null
     */
    private function resolveCoordinatePairs(
        int|float|string|UInt64|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ExifRationalList|ExifNumericList|null {
        if ($value instanceof ExifRationalList) {
            return $value;
        }

        if ($value instanceof ExifNumericList) {
            return $value;
        }

        return null;
    }

    /**
     * Decodes undefined GPS ASCII strings with optional encoding prefixes.
     *
     * EXIF 3.0 §4.6.8 (GPSProcessingMethod/GPSAreaInformation) defines encoding prefixes.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     */
    private function decodeUndefinedString(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $payload  = $value;
        $encoding = null;

        $prefixes = [
            "ASCII\0\0\0"   => CharacterEncoding::ASCII,
            "UNICODE\0"     => CharacterEncoding::UTF16LE,
            "JIS\0\0\0\0\0" => CharacterEncoding::JIS,
        ];

        foreach ($prefixes as $prefix => $encodingEnum) {
            if (str_starts_with($payload, $prefix)) {
                $payload  = substr($payload, strlen($prefix));
                $encoding = $encodingEnum;
                break;
            }
        }

        return match ($encoding) {
            CharacterEncoding::UTF16LE => $this->decodeUndefinedUnicode($payload),
            CharacterEncoding::JIS     => $this->decodeUndefinedJis($payload),
            null                       => $this->stringConverter->sanitize($payload),
            default                    => $this->stringConverter->sanitize($payload),
        };
    }

    /**
     * Decodes a UTF-16 encoded undefined GPS string into UTF-8.
     */
    private function decodeUndefinedUnicode(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $converted = @iconv('UTF-16LE', 'UTF-8', $payload);
        if ($converted === false) {
            $converted = @iconv('UTF-16BE', 'UTF-8', $payload);
        }

        if ($converted !== false) {
            return $this->stringConverter->sanitize($converted);
        }

        $stripped = preg_replace('/\x00/u', '', $payload);
        if ($stripped === null) {
            return null;
        }

        return $this->stringConverter->sanitize($stripped);
    }

    /**
     * Decodes a Shift-JIS encoded undefined GPS string into UTF-8.
     */
    private function decodeUndefinedJis(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $converted = @iconv('SJIS', 'UTF-8', $payload);
        if ($converted !== false) {
            return $this->stringConverter->sanitize($converted);
        }

        return $this->stringConverter->sanitize($payload);
    }
}
