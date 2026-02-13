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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;

use function abs;
use function array_find;
use function checkdate;
use function count;
use function floor;
use function iconv;
use function implode;
use function in_array;
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
     * EXIF 3.0 §4.6.7.1.2 GPSLatitudeRef: 'N' (north) or 'S' (south).
     *
     * @var list<string>
     */
    private const array GPS_LATITUDE_REF_VALUES = ['N', 'S'];

    /**
     * EXIF 3.0 §4.6.7.1.4 GPSLongitudeRef: 'E' (east) or 'W' (west).
     *
     * @var list<string>
     */
    private const array GPS_LONGITUDE_REF_VALUES = ['E', 'W'];

    /**
     * EXIF 3.0 §4.6.7.1.10 GPSStatus: 'A' (measurement in progress) or 'V' (measurement interrupted).
     *
     * @var list<string>
     */
    private const array GPS_STATUS_VALUES = ['A', 'V'];

    /**
     * EXIF 3.0 §4.6.7.1.11 GPSMeasureMode: '2' (2D) or '3' (3D).
     *
     * @var list<string>
     */
    private const array GPS_MEASURE_MODE_VALUES = ['2', '3'];

    /**
     * EXIF 3.0 §4.6.7.1.13 GPSSpeedRef: 'K' (km/h), 'M' (mph) or 'N' (knots).
     *
     * @var list<string>
     */
    private const array GPS_SPEED_REF_VALUES = ['K', 'M', 'N'];

    /**
     * EXIF 3.0 §4.6.7.1.15 GPSTrackRef, §4.6.7.1.17 GPSImgDirectionRef, §4.6.7.1.24 GPSDestBearingRef:
     * 'T' (true direction) or 'M' (magnetic direction).
     *
     * @var list<string>
     */
    private const array GPS_BEARING_REF_VALUES = ['T', 'M'];

    /**
     * EXIF 3.0 §4.6.7.1.26 GPSDestDistanceRef: 'K' (km), 'M' (miles) or 'N' (nautical miles).
     *
     * @var list<string>
     */
    private const array GPS_DISTANCE_REF_VALUES = ['K', 'M', 'N'];

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

        // EXIF 3.0 §4.6.7.1.2 GPSLatitudeRef: 'N' or 'S'
        $result['lat_ref'] = $this->validateGpsRef(
            is_string($latRef) ? strtoupper(trim($latRef)) : null,
            self::GPS_LATITUDE_REF_VALUES,
        );
        // EXIF 3.0 §4.6.7.1.4 GPSLongitudeRef: 'E' or 'W'
        $result['lon_ref'] = $this->validateGpsRef(
            is_string($lonRef) ? strtoupper(trim($lonRef)) : null,
            self::GPS_LONGITUDE_REF_VALUES,
        );

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

        // GH-935: EXIF 3.0 §4.6.7.1.1 — when GPSVersionID is present, validate it.
        $versionParts      = $this->formatVersion($versionEntry?->value);
        $result['version'] = $versionParts['normalized'];
        if ($versionEntry instanceof IfdEntry && $result['version'] !== self::DEFAULT_GPS_VERSION) {
            throw new ParseError(sprintf(
                'GPSVersionID "%s" is reserved; only "2.4.0.0" is allowed per EXIF 3.0 §4.6.7.1.1.',
                $result['version'],
            ), 1462);
        }

        $result['version_raw'] = $versionParts['raw'];
        $result['satellites']  = $this->stringConverter->sanitize($satellitesEntry?->value);

        // EXIF 3.0 §4.6.7.1.10 GPSStatus: 'A' (measurement in progress) or 'V' (measurement interrupted)
        $statusSanitized  = $this->stringConverter->sanitize($statusEntry?->value);
        $result['status'] = $this->validateGpsRef(
            is_string($statusSanitized) ? strtoupper(trim($statusSanitized)) : null,
            self::GPS_STATUS_VALUES,
        );

        // EXIF 3.0 §4.6.7.1.11 GPSMeasureMode: '2' (2D) or '3' (3D)
        $measureSanitized       = $this->stringConverter->sanitize($measureEntry?->value);
        $result['measure_mode'] = $this->validateGpsRef(
            is_string($measureSanitized) ? strtoupper(trim($measureSanitized)) : null,
            self::GPS_MEASURE_MODE_VALUES,
        );
        $result['dop'] = $this->rationalConverter->toFloat($dopEntry?->value);

        // EXIF 3.0 §4.6.7.1.13 GPSSpeedRef: 'K', 'M' or 'N'; default 'K'
        $speedRefValue    = $speedRefEntry?->value;
        $speedOriginalRef = $this->stringConverter->sanitize($speedRefValue);
        $speedRef         = $this->validateGpsRef(
            is_string($speedRefValue) ? strtoupper(trim($speedRefValue)) : null,
            self::GPS_SPEED_REF_VALUES,
        );
        if ($speedRef === null && !$speedRefEntry instanceof IfdEntry && $speedEntry instanceof IfdEntry) {
            $speedRef = 'K';
        }

        $result['speed_ref']          = $speedRef;
        $result['speed_ms']           = $this->speedToMs($speedRef, $speedEntry?->value);
        $result['speed_original_ref'] = $speedOriginalRef;
        $result['speed_original']     = $this->rationalConverter->toFloat($speedEntry?->value);

        // EXIF 3.0 §4.6.7.1.15 GPSTrackRef: 'T' or 'M'; default 'T'
        $trackRefValue       = $trackRefEntry?->value;
        $trackRefNormalized  = is_string($trackRefValue) ? strtoupper(trim($trackRefValue)) : null;
        $result['track_ref'] = $this->validateGpsRef($trackRefNormalized, self::GPS_BEARING_REF_VALUES);
        $trackRefInvalid     = ($trackRefNormalized !== null) && ($result['track_ref'] === null);
        if ($result['track_ref'] === null && !$trackRefEntry instanceof IfdEntry && $trackEntry instanceof IfdEntry) {
            $result['track_ref'] = 'T';
        }

        $trackValue      = $this->rationalConverter->toFloat($trackEntry?->value);
        $result['track'] = $trackRefInvalid ? null : $this->normalizeBearing($trackValue);

        // EXIF 3.0 §4.6.7.1.17 GPSImgDirectionRef: 'T' or 'M'; default 'T'
        $imgDirRefValue              = $imgDirRefEntry?->value;
        $imgDirRefNormalized         = is_string($imgDirRefValue) ? strtoupper(trim($imgDirRefValue)) : null;
        $result['img_direction_ref'] = $this->validateGpsRef($imgDirRefNormalized, self::GPS_BEARING_REF_VALUES);
        $imgDirRefInvalid            = ($imgDirRefNormalized !== null) && ($result['img_direction_ref'] === null);
        if ($result['img_direction_ref'] === null && !$imgDirRefEntry instanceof IfdEntry && $imgDirEntry instanceof IfdEntry) {
            $result['img_direction_ref'] = 'T';
        }

        $imgDirectionValue       = $this->rationalConverter->toFloat($imgDirEntry?->value);
        $result['img_direction'] = $imgDirRefInvalid ? null : $this->normalizeBearing($imgDirectionValue);

        $result['map_datum'] = $this->stringConverter->sanitize($mapDatumEntry?->value);

        // EXIF 3.0 §4.6.7.1.20 GPSDestLatitudeRef: 'N' or 'S'
        $destLatRefValue        = $destLatRefEntry?->value;
        $destLatVal             = $destLatEntry?->value;
        $destLatPairs           = $destLatVal instanceof ExifRationalList ? $destLatVal : null;
        $result['dest_lat_ref'] = $this->validateGpsRef(
            is_string($destLatRefValue) ? strtoupper(trim($destLatRefValue)) : null,
            self::GPS_LATITUDE_REF_VALUES,
        );
        $result['dest_lat'] = $this->dmsToFloat($result['dest_lat_ref'], $destLatPairs);

        // EXIF 3.0 §4.6.7.1.22 GPSDestLongitudeRef: 'E' or 'W'
        $destLonRefValue        = $destLonRefEntry?->value;
        $destLonVal             = $destLonEntry?->value;
        $destLonPairs           = $destLonVal instanceof ExifRationalList ? $destLonVal : null;
        $result['dest_lon_ref'] = $this->validateGpsRef(
            is_string($destLonRefValue) ? strtoupper(trim($destLonRefValue)) : null,
            self::GPS_LONGITUDE_REF_VALUES,
        );
        $result['dest_lon'] = $this->dmsToFloat($result['dest_lon_ref'], $destLonPairs);

        // EXIF 3.0 §4.6.7.1.24 GPSDestBearingRef: 'T' or 'M'; default 'T'
        $destBearingRefValue        = $destBearRefEntry?->value;
        $destBearingRefNormalized   = is_string($destBearingRefValue) ? strtoupper(trim($destBearingRefValue)) : null;
        $result['dest_bearing_ref'] = $this->validateGpsRef($destBearingRefNormalized, self::GPS_BEARING_REF_VALUES);
        $destBearingRefInvalid      = ($destBearingRefNormalized !== null) && ($result['dest_bearing_ref'] === null);
        if ($result['dest_bearing_ref'] === null && !$destBearRefEntry instanceof IfdEntry && $destBearEntry instanceof IfdEntry) {
            $result['dest_bearing_ref'] = 'T';
        }

        $destBearingValue       = $this->rationalConverter->toFloat($destBearEntry?->value);
        $result['dest_bearing'] = $destBearingRefInvalid ? null : $this->normalizeBearing($destBearingValue);

        // EXIF 3.0 §4.6.7.1.26 GPSDestDistanceRef: 'K', 'M' or 'N'; default 'K'
        $destDistanceRefValue        = $destDistRefEntry?->value;
        $result['dest_distance_ref'] = $this->validateGpsRef(
            is_string($destDistanceRefValue) ? strtoupper(trim($destDistanceRefValue)) : null,
            self::GPS_DISTANCE_REF_VALUES,
        );
        if ($result['dest_distance_ref'] === null && !$destDistRefEntry instanceof IfdEntry && $destDistEntry instanceof IfdEntry) {
            $result['dest_distance_ref'] = 'K';
        }

        $result['dest_distance_original_ref'] = $this->stringConverter->sanitize($destDistanceRefValue);
        $result['dest_distance_original']     = $this->rationalConverter->toFloat($destDistEntry?->value);
        $result['dest_distance_m']            = $this->distanceToMetres($result['dest_distance_ref'], $destDistEntry?->value);

        $result['processing_method'] = $this->decodeUndefinedString($processEntry?->value);
        $result['area_information']  = $this->decodeUndefinedString($areaEntry?->value);

        $dateParts = $this->normalizeDate($dateEntry?->value);
        if (($dateEntry instanceof IfdEntry) && ($dateParts['normalized'] === null)) {
            throw new ParseError(
                sprintf(
                    'GPSDateStamp "%s" is not a valid UTC calendar date per EXIF 3.0 §4.6.7.1.30.',
                    $dateParts['raw'] ?? '',
                ),
                1465,
            );
        }

        $result['date']     = $dateParts['normalized'];
        $result['date_raw'] = $dateParts['raw'];

        $timeParts = $timeEntry instanceof IfdEntry && $timeEntry->value instanceof ExifRationalList
            ? $this->parseTime($timeEntry->value)
            : null;

        if (($timeEntry instanceof IfdEntry) && ($timeParts === null)) {
            throw new ParseError(
                'GPSTimeStamp is outside valid UTC ranges (hour 0..23, minute 0..59, second >=0 and <60) per EXIF 3.0 §4.6.7.1.8.',
                1466,
            );
        }

        $result['time']      = $this->formatTime($timeParts);
        $result['timestamp'] = $this->combineDateTime($result['date'], $timeParts);

        $diffEntry = $gps->get(ExifTag::GPS_DIFFERENTIAL);
        $diffValue = $diffEntry?->value;
        if ($diffValue instanceof ExifNumericList) {
            $diffValue = $diffValue->values[0] ?? null;
        }

        if (is_int($diffValue) && ($diffValue === 0 || $diffValue === 1)) {
            $result['differential'] = $diffValue;
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
     * Validates a compass bearing is within the strict [0, 360) range.
     *
     * EXIF 3.0 §4.6.7.1.16/§4.6.7.1.18/§4.6.7.1.25: bearings must be 0.00–359.99.
     */
    public function normalizeBearing(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $bearing = (float) $value;
        if ($bearing < 0.0 || $bearing >= 360.0) {
            throw new ParseError(sprintf(
                'GPS bearing value %s is outside the valid range 0.00–359.99 per EXIF 3.0.',
                $bearing,
            ), 1460);
        }

        return $bearing;
    }

    /**
     * Validates a GPS reference value against a list of allowed spec values.
     *
     * EXIF 3.0 §4.6.7 defines enumerated values for each GPS reference/status tag;
     * any value not in the allowed set is treated as reserved and rejected.
     *
     * @param string|null  $value   Normalised (uppercase, trimmed) reference value.
     * @param list<string> $allowed Permitted values from the EXIF 3.0 specification.
     *
     * @return string|null The value if valid, null otherwise.
     */
    private function validateGpsRef(?string $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
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

        $isLatitudeRef  = ($ref === 'N') || ($ref === 'S');
        $isLongitudeRef = ($ref === 'E') || ($ref === 'W');
        if (!$isLatitudeRef && !$isLongitudeRef) {
            return null;
        }

        $components = [];

        // EXIF 3.0 §4.6.8: GPSLatitude/GPSLongitude require exactly 3 RATIONAL
        // components (degrees, minutes, seconds). Non-conformant counts are rejected.
        if ($val instanceof ExifRationalList) {
            if (count($val->values) !== 3) {
                return null;
            }

            foreach ($val->values as $index => $component) {
                $numeric = $this->rationalConverter->toFloat($component);
                if ($numeric === null) {
                    return null;
                }

                if ($numeric < 0.0) {
                    $part = match ($index) {
                        0       => 'degrees',
                        1       => 'minutes',
                        default => 'seconds',
                    };

                    throw new ParseError(
                        sprintf(
                            'GPS %s component must be non-negative; hemisphere direction is defined by GPS reference tags per EXIF 3.0 §4.6.7.1.2-§4.6.7.1.5.',
                            $part,
                        ),
                        1467,
                    );
                }

                $components[] = $numeric;
            }
        } else {
            if (count($val->values) !== 3) {
                return null;
            }

            foreach ($val->values as $index => $component) {
                $numeric = $this->numericConverter->normaliseComponent($component);
                if ($numeric === null) {
                    return null;
                }

                if ($numeric < 0.0) {
                    $part = match ($index) {
                        0       => 'degrees',
                        1       => 'minutes',
                        default => 'seconds',
                    };

                    throw new ParseError(
                        sprintf(
                            'GPS %s component must be non-negative; hemisphere direction is defined by GPS reference tags per EXIF 3.0 §4.6.7.1.2-§4.6.7.1.5.',
                            $part,
                        ),
                        1467,
                    );
                }

                $components[] = $numeric;
            }
        }

        $deg = $components[0];
        $min = $components[1];
        $sec = $components[2];

        $sign  = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;
        $value = $sign * ($deg + $min / 60.0 + $sec / 3600.0);

        if ($isLatitudeRef && (($value < -90.0) || ($value > 90.0))) {
            throw new ParseError(sprintf(
                'GPS coordinate %s is outside the valid latitude range [-90, 90] per EXIF 3.0 §4.6.7.1.3.',
                $value,
            ), 1463);
        }

        if ($isLongitudeRef && (($value < -180.0) || ($value > 180.0))) {
            throw new ParseError(sprintf(
                'GPS coordinate %s is outside the valid longitude range [-180, 180] per EXIF 3.0 §4.6.7.1.5.',
                $value,
            ), 1464);
        }

        return $value;
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

        $year  = (int) substr($clean, 0, 4);
        $month = (int) substr($clean, 5, 2);
        $day   = (int) substr($clean, 8, 2);
        if (!checkdate($month, $day, $year)) {
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
        if (count($value->values) !== 3) {
            return null;
        }

        $hours   = $this->rationalConverter->toFloat($value->values[0]);
        $minutes = $this->rationalConverter->toFloat($value->values[1]);
        $seconds = $this->rationalConverter->toFloat($value->values[2]);

        if ($hours === null || $minutes === null || $seconds === null) {
            return null;
        }

        if (!$this->isWholeNumber($hours) || !$this->isWholeNumber($minutes)) {
            return null;
        }

        $hoursInt   = (int) $hours;
        $minutesInt = (int) $minutes;

        if (($hoursInt < 0) || ($hoursInt > 23)) {
            return null;
        }

        if (($minutesInt < 0) || ($minutesInt > 59)) {
            return null;
        }

        // Leap seconds are not accepted; EXIF GPS timestamps are restricted to [0, 60).
        if (($seconds < 0.0) || ($seconds >= 60.0)) {
            return null;
        }

        return [
            'hours'   => $hoursInt,
            'minutes' => $minutesInt,
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

    private function isWholeNumber(float $value): bool
    {
        return abs($value - floor($value)) < 1.0e-9;
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
    /**
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes or with an
     * unrecognised prefix are rejected.
     */
    private function decodeUndefinedString(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        if (!is_string($value) || strlen($value) < 8) {
            return null;
        }

        $prefixBytes = substr($value, 0, 8);
        $payload     = substr($value, 8);

        $prefixes = [
            "ASCII\0\0\0"   => CharacterEncoding::ASCII,
            "UNICODE\0"     => CharacterEncoding::UTF16LE,
            "JIS\0\0\0\0\0" => CharacterEncoding::JIS,
        ];

        $encoding = array_find($prefixes, fn (CharacterEncoding $encodingEnum, string $prefix): bool => $prefixBytes === $prefix);

        // EXIF 3.0 §4.6.4: all-NULL prefix denotes UNDEFINED encoding
        if ($encoding === null && trim($prefixBytes, "\0") === '') {
            return $this->stringConverter->sanitize($payload);
        }

        if ($encoding === null) {
            return null;
        }

        return match ($encoding) {
            CharacterEncoding::UTF16LE => $this->decodeUndefinedUnicode($payload),
            CharacterEncoding::JIS     => $this->decodeUndefinedJis($payload),
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
