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
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;

use function array_replace;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;
use function strtoupper;
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
    use ValidatesGpsRef;

    /**
     * EXIF 3.0 §4.6.7.1.1 (GPSVersionID) default value when the field is blank.
     */
    private const string DEFAULT_GPS_VERSION    = '2.4.0.0';

    /**
     * EXIF 3.0 §4.6.7.1.10 GPSStatus: 'A' (measurement in progress) or 'V' (measurement interrupted).
     *
     * @var list<string>
     */
    private const array GPS_STATUS_VALUES       = ['A', 'V'];

    /**
     * EXIF 3.0 §4.6.7.1.11 GPSMeasureMode: '2' (2D) or '3' (3D).
     *
     * @var list<string>
     */
    private const array GPS_MEASURE_MODE_VALUES = ['2', '3'];

    /**
     * @param GpsCoordinateConverter $coordinateConverter Coordinate DMS-to-decimal converter.
     * @param GpsUnitConverter       $unitConverter       Speed/distance/altitude converter.
     * @param GpsDirectionConverter  $directionConverter  Bearing normalisation converter.
     * @param GpsTimestampConverter  $timestampConverter  Date/time assembly converter.
     * @param RationalConverter      $rationalConverter   Dependency for rational conversions.
     * @param StringConverter        $stringConverter     Dependency for string sanitisation.
     */
    public function __construct(
        private GpsCoordinateConverter $coordinateConverter,
        private GpsUnitConverter $unitConverter,
        private GpsDirectionConverter $directionConverter,
        private GpsTimestampConverter $timestampConverter,
        private RationalConverter $rationalConverter,
        private StringConverter $stringConverter,
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
        $result                        = $this->emptyGpsResult();

        // Delegate domain extractions
        $result                        = array_replace($result, $this->coordinateConverter->extractFromIfd($gps));
        $result                        = array_replace($result, $this->unitConverter->extractFromIfd($gps));
        $result                        = array_replace($result, $this->directionConverter->extractFromIfd($gps));
        $result                        = array_replace($result, $this->timestampConverter->extractFromIfd($gps));

        // Remaining simple fields handled by the orchestrator
        $versionEntry                  = $gps->get(ExifTag::GPS_VERSION_ID);
        $satellitesEntry               = $gps->get(ExifTag::GPS_SATELLITES);
        $statusEntry                   = $gps->get(ExifTag::GPS_STATUS);
        $measureEntry                  = $gps->get(ExifTag::GPS_MEASURE_MODE);
        $dopEntry                      = $gps->get(ExifTag::GPS_DOP);
        $mapDatumEntry                 = $gps->get(ExifTag::GPS_MAP_DATUM);

        $versionParts                  = $this->formatVersion($versionEntry?->value);
        $result['version']             = $versionParts['normalized'];
        $result['version_raw']         = $versionParts['raw'];
        $result['satellites']          = $this->stringConverter->sanitize($satellitesEntry?->value);

        // EXIF 3.0 §4.6.7.1.10 GPSStatus: 'A' (measurement in progress) or 'V' (measurement interrupted)
        $statusSanitized               = $this->stringConverter->sanitize($statusEntry?->value);
        $result['status']              = $this->validateGpsRef(
            is_string($statusSanitized) ? strtoupper(trim($statusSanitized)) : null,
            self::GPS_STATUS_VALUES,
        );

        // EXIF 3.0 §4.6.7.1.11 GPSMeasureMode: '2' (2D) or '3' (3D)
        $measureSanitized              = $this->stringConverter->sanitize($measureEntry?->value);
        $result['measure_mode']        = $this->validateGpsRef(
            is_string($measureSanitized) ? strtoupper(trim($measureSanitized)) : null,
            self::GPS_MEASURE_MODE_VALUES,
        );

        $dopValue                      = $this->rationalConverter->toFloat($dopEntry?->value);

        // Tolerate negative DOP — set to null.
        if (($dopValue !== null) && ($dopValue < 0.0)) {
            $dopValue = null;
        }

        $result['dop']                 = $dopValue;
        $result['map_datum']           = $this->stringConverter->sanitize($mapDatumEntry?->value);

        // Differential
        $diffEntry                     = $gps->get(ExifTag::GPS_DIFFERENTIAL);
        $diffValue                     = $diffEntry?->value;

        if ($diffValue instanceof ExifNumericList) {
            $diffValue = $diffValue->values[0] ?? null;
        }

        if (is_int($diffValue) && (GpsDifferential::tryFrom($diffValue) !== null)) {
            $result['differential'] = $diffValue;
        }

        // Horizontal positioning error
        $hPositionEntry                = $gps->get(ExifTag::GPS_H_POSITIONING_ERROR);
        $hPositioningErrorValue        = $this->rationalConverter->toFloat($hPositionEntry?->value);

        // Tolerate negative HPositioningError — set to null.
        if (($hPositioningErrorValue !== null) && ($hPositioningErrorValue < 0.0)) {
            $hPositioningErrorValue = null;
        }

        $result['h_positioning_error'] = $hPositioningErrorValue;

        return $result;
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
}
