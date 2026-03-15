<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;

use function array_map;
use function count;
use function floor;
use function fmod;
use function is_string;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Converts GPS coordinate values from DMS to decimal degrees.
 *
 * EXIF 3.0 §4.6.7.1.2–§4.6.7.1.5 define the coordinate reference tags;
 * §4.6.8 specifies the GPSLatitude/GPSLongitude DMS encoding.
 */
final readonly class GpsCoordinateConverter
{
    use ValidatesGpsRef;

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
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     * @param NumericConverter  $numericConverter  Dependency for numeric conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
        private NumericConverter $numericConverter,
    ) {
    }

    /**
     * Extracts capture and destination coordinate data from a GPS IFD.
     *
     * @return array{
     *     lat_ref: ?string,
     *     lat: ?float,
     *     lon_ref: ?string,
     *     lon: ?float,
     *     dest_lat_ref: ?string,
     *     dest_lat: ?float,
     *     dest_lon_ref: ?string,
     *     dest_lon: ?float,
     * }
     */
    public function extractFromIfd(Ifd $gps): array
    {
        [$latRefNorm, $latPairs]         = $this->resolveAxis($gps, ExifTag::GPS_LATITUDE_REF, ExifTag::GPS_LATITUDE, self::GPS_LATITUDE_REF_VALUES);
        [$lonRefNorm, $lonPairs]         = $this->resolveAxis($gps, ExifTag::GPS_LONGITUDE_REF, ExifTag::GPS_LONGITUDE, self::GPS_LONGITUDE_REF_VALUES);
        [$destLatRefNorm, $destLatPairs] = $this->resolveAxis($gps, ExifTag::GPS_DEST_LATITUDE_REF, ExifTag::GPS_DEST_LATITUDE, self::GPS_LATITUDE_REF_VALUES);
        [$destLonRefNorm, $destLonPairs] = $this->resolveAxis($gps, ExifTag::GPS_DEST_LONGITUDE_REF, ExifTag::GPS_DEST_LONGITUDE, self::GPS_LONGITUDE_REF_VALUES);

        return [
            'lat_ref'      => $latRefNorm,
            'lat'          => $this->dmsToFloat($latRefNorm, $latPairs),
            'lon_ref'      => $lonRefNorm,
            'lon'          => $this->dmsToFloat($lonRefNorm, $lonPairs),
            'dest_lat_ref' => $destLatRefNorm,
            'dest_lat'     => $this->dmsToFloat($destLatRefNorm, $destLatPairs),
            'dest_lon_ref' => $destLonRefNorm,
            'dest_lon'     => $this->dmsToFloat($destLonRefNorm, $destLonPairs),
        ];
    }

    /**
     * Resolves a single coordinate axis (ref + value) from the GPS IFD.
     *
     * @param Ifd          $gps              GPS IFD to read from.
     * @param int          $refTag           Tag constant for the reference direction.
     * @param int          $valTag           Tag constant for the coordinate value.
     * @param list<string> $allowedRefValues Permitted reference direction values.
     *
     * @return array{0: ?string, 1: ExifRationalList|ExifNumericList|null}
     */
    private function resolveAxis(Ifd $gps, int $refTag, int $valTag, array $allowedRefValues): array
    {
        $refEntry = $gps->get($refTag);
        $valEntry = $gps->get($valTag);

        if ($this->isCoordinatePairIncomplete($refEntry, $valEntry)) {
            return [null, null];
        }

        $ref     = $refEntry?->value;
        $refNorm = $this->validateGpsRef(
            is_string($ref) ? strtoupper(trim($ref)) : null,
            $allowedRefValues,
        );
        $pairs = $this->resolveCoordinatePairs($valEntry?->value);

        return [$refNorm, $pairs];
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

        // EXIF 3.0 §4.6.8: GPSLatitude/GPSLongitude require exactly 3 RATIONAL
        // components (degrees, minutes, seconds). Non-conformant counts are rejected.
        $numericValues = $val instanceof ExifRationalList
            ? array_map(
                $this->rationalConverter->toFloat(...),
                $val->values,
            )
            : array_map(
                $this->numericConverter->normalizeComponent(...),
                $val->values,
            );

        $components = $this->validateDmsComponents($numericValues);

        if ($components === null) {
            return null;
        }

        $deg = $components[0];
        $min = $components[1];
        $sec = $components[2];

        // Tolerate minutes/seconds >= 60 — carry over into the next component.
        if ($sec >= 60.0) {
            $min += floor($sec / 60.0);
            $sec = fmod($sec, 60.0);
        }

        if ($min >= 60.0) {
            $deg += floor($min / 60.0);
            $min = fmod($min, 60.0);
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        // EXIF 3.0 §4.6.7.1.3 and §4.6.7.1.5 define nominal latitude/longitude
        // ranges, but reader-side Postel handling keeps out-of-range camera data
        // as-is so downstream consumers can decide how to interpret it.

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }

    /**
     * Validates that DMS components are non-negative and returns them as a float triplet.
     *
     * @param list<?float> $numericValues Converted numeric values (degrees, minutes, seconds).
     *
     * @return list<float>|null
     */
    private function validateDmsComponents(array $numericValues): ?array
    {
        if (count($numericValues) !== 3) {
            return null;
        }

        $components = [];

        foreach ($numericValues as $index => $numeric) {
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

        return $components;
    }

    /**
     * Resolves EXIF GPS degrees/minutes/seconds into a numeric list.
     */
    private function resolveCoordinatePairs(
        int|float|string|UInt64|ExifRationalList|ExifNumericList|ExifRational|null $value,
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
     * Checks that GPS coordinate ref and value tags are either both present or both absent.
     *
     * Tolerates mismatches by returning true when the pair is incomplete.
     *
     * @return bool True when the pair is incomplete and should be skipped.
     */
    private function isCoordinatePairIncomplete(
        ?IfdEntry $refEntry,
        ?IfdEntry $valueEntry,
    ): bool {
        return $refEntry instanceof IfdEntry !== $valueEntry instanceof IfdEntry;
    }
}
