<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;

use function abs;
use function floor;
use function is_callable;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function strtoupper;
use function trim;

/**
 * Converts GPS speed, distance and altitude values with unit normalisation.
 *
 * EXIF 3.0 §4.6.7.1.6–§4.6.7.1.7 (altitude), §4.6.7.1.13–§4.6.7.1.14 (speed),
 * §4.6.7.1.26–§4.6.7.1.27 (destination distance) define the tags decoded here.
 */
final readonly class GpsUnitConverter
{
    use ValidatesGpsRef;

    /**
     * EXIF 3.0 §4.6.7.1.13 GPSSpeedRef: 'K' (km/h), 'M' (mph) or 'N' (knots).
     *
     * @var list<string>
     */
    private const array GPS_SPEED_REF_VALUES = ['K', 'M', 'N'];

    /**
     * EXIF 3.0 §4.6.7.1.26 GPSDestDistanceRef: 'K' (km), 'M' (miles) or 'N' (nautical miles).
     *
     * @var list<string>
     */
    private const array GPS_DISTANCE_REF_VALUES = ['K', 'M', 'N'];

    /**
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
    ) {
    }

    /**
     * Extracts altitude, speed and destination distance data from a GPS IFD.
     *
     * @return array{
     *     alt_ref: ?int,
     *     alt: ?float,
     *     speed_ref: ?string,
     *     speed_ms: ?float,
     *     speed_original_ref: ?string,
     *     speed_original: ?float,
     *     dest_distance_ref: ?string,
     *     dest_distance_m: ?float,
     *     dest_distance_original_ref: ?string,
     *     dest_distance_original: ?float,
     * }
     */
    public function extractFromIfd(Ifd $gps): array
    {
        $result = [
            'alt_ref'                    => null,
            'alt'                        => null,
            'speed_ref'                  => null,
            'speed_ms'                   => null,
            'speed_original_ref'         => null,
            'speed_original'             => null,
            'dest_distance_ref'          => null,
            'dest_distance_m'            => null,
            'dest_distance_original_ref' => null,
            'dest_distance_original'     => null,
        ];

        // Altitude
        $altRefEntry = $gps->get(ExifTag::GPS_ALTITUDE_REF);
        $altRefValue = $altRefEntry?->value;
        $altRef      = $this->normalizeAltitudeRef($altRefValue);
        if ($altRef !== null) {
            $result['alt_ref'] = $altRef;
        }

        $altEntry = $gps->get(ExifTag::GPS_ALTITUDE);
        if ($altEntry instanceof IfdEntry) {
            // EXIF 3.0 §4.6.7.1.6: default GPSAltitudeRef is 0 when tag is missing
            if ($result['alt_ref'] === null) {
                $result['alt_ref'] = 0;
            }

            $alt = $this->rationalConverter->toFloat($altEntry->value);

            // Tolerate negative altitude — use absolute magnitude.
            if (($alt !== null) && ($alt < 0.0)) {
                $alt = -$alt;
            }

            // EXIF 3.0 §4.6.7.1.6: Values 1 (below ellipsoidal) and 3 (below sea level) indicate negative altitude
            if (($alt !== null) && (GpsAltitudeRef::tryFrom($result['alt_ref'])?->isBelow() === true)) {
                $alt = -$alt;
            }

            if ($alt !== null) {
                $result['alt'] = $alt;
            }
        }

        // Speed
        $speedRefEntry = $gps->get(ExifTag::GPS_SPEED_REF);
        $speedEntry    = $gps->get(ExifTag::GPS_SPEED);
        $speedRefValue = $speedRefEntry?->value;

        $speedOriginalRef = $this->validateGpsRef(
            is_string($speedRefValue) ? strtoupper(trim($speedRefValue)) : null,
            self::GPS_SPEED_REF_VALUES,
        );
        $speedRef = $this->validateGpsRef(
            is_string($speedRefValue) ? strtoupper(trim($speedRefValue)) : null,
            self::GPS_SPEED_REF_VALUES,
        );
        if (($speedRef === null) && (!$speedRefEntry instanceof IfdEntry) && ($speedEntry instanceof IfdEntry)) {
            $speedRef = 'K';
        }

        $result['speed_ref']          = $speedRef;
        $result['speed_ms']           = $this->speedToMs($speedRef, $speedEntry?->value);
        $result['speed_original_ref'] = $speedOriginalRef;
        $result['speed_original']     = $this->rationalConverter->toFloat($speedEntry?->value);

        // Destination distance
        $destDistRefEntry     = $gps->get(ExifTag::GPS_DEST_DISTANCE_REF);
        $destDistEntry        = $gps->get(ExifTag::GPS_DEST_DISTANCE);
        $destDistanceRefValue = $destDistRefEntry?->value;

        $destDistanceOriginalRef = $this->validateGpsRef(
            is_string($destDistanceRefValue) ? strtoupper(trim($destDistanceRefValue)) : null,
            self::GPS_DISTANCE_REF_VALUES,
        );
        $result['dest_distance_ref'] = $this->validateGpsRef(
            is_string($destDistanceRefValue) ? strtoupper(trim($destDistanceRefValue)) : null,
            self::GPS_DISTANCE_REF_VALUES,
        );
        if (($result['dest_distance_ref'] === null) && (!$destDistRefEntry instanceof IfdEntry) && ($destDistEntry instanceof IfdEntry)) {
            $result['dest_distance_ref'] = 'K';
        }

        $result['dest_distance_original_ref'] = $destDistanceOriginalRef;
        $result['dest_distance_original']     = $this->rationalConverter->toFloat($destDistEntry?->value);
        $result['dest_distance_m']            = $this->distanceToMetres($result['dest_distance_ref'], $destDistEntry?->value);

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
     * Normalizes the GPS altitude reference into a valid EXIF 3.0 §4.6.7.1.6 value.
     *
     * @return int|null 0-3 per EXIF 3.0 specification, null when unknown.
     */
    public function normalizeAltitudeRef(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?int {
        if ($value instanceof ExifNumericList) {
            $component = $value->values[0] ?? null;

            return $this->normalizeAltitudeRef($component);
        }

        if ($value instanceof ExifRationalList) {
            $component = $value->values[0] ?? null;

            return $component instanceof ExifRational
                ? $this->normalizeAltitudeRef($component)
                : null;
        }

        if ($value instanceof ExifRational) {
            $numeric = $this->rationalConverter->toFloat($value);
            if (($numeric === null) || !$this->isWholeNumber($numeric)) {
                return null;
            }

            return $this->normalizeAltitudeRef((int) $numeric);
        }

        if (is_string($value)) {
            $clean = trim($value);
            if ($clean === '' || !is_numeric($clean)) {
                return null;
            }

            if (preg_match('/^[+-]?\d+$/', $clean) !== 1) {
                return null;
            }

            return $this->normalizeAltitudeRef((int) $clean);
        }

        if (is_int($value)) {
            $normalized = $value;

            // EXIF 3.0 §4.6.7.1.6: Valid values are 0-3
            if ($normalized < 0 || $normalized > 3) {
                return null;
            }

            return $normalized;
        }

        if (is_float($value)) {
            if (!$this->isWholeNumber($value)) {
                return null;
            }

            $normalized = (int) $value;

            // EXIF 3.0 §4.6.7.1.6: Valid values are 0-3
            if ($normalized < 0 || $normalized > 3) {
                return null;
            }

            return $normalized;
        }

        return null;
    }

    /**
     * Normalizes a numeric GPS value and its reference string.
     *
     * @param string|null                                                                $ref   Reference string.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw numeric value.
     *
     * @return array{ref:string, value:float}|null Normalized reference/value pair or null.
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

    private function isWholeNumber(float $value): bool
    {
        return abs($value - floor($value)) < 1.0e-9;
    }
}
