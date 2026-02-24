<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;

use function abs;

/**
 * Describes the primary GPS position including coordinates, altitude, and geodetic datum.
 */
final readonly class GpsPosition
{
    public ?float $latitudeSigned;

    public ?float $longitudeSigned;

    public ?GpsCoordinate $latitudeCoordinate;

    public ?GpsCoordinate $longitudeCoordinate;

    /**
     * @param float|null          $latitude     Latitude in decimal degrees.
     * @param float|null          $longitude    Longitude in decimal degrees.
     * @param GpsLatLonRef|null   $latitudeRef  Latitude hemisphere reference (N/S).
     * @param GpsLatLonRef|null   $longitudeRef Longitude hemisphere reference (E/W).
     * @param float|null          $altitude     Altitude in metres relative to sea level.
     * @param GpsAltitudeRef|null $altitudeRef  Altitude reference (above/below sea level).
     * @param string|null         $mapDatum     Geodetic survey data used.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?GpsLatLonRef $latitudeRef = null,
        public ?GpsLatLonRef $longitudeRef = null,
        public ?float $altitude = null,
        public ?GpsAltitudeRef $altitudeRef = null,
        public ?string $mapDatum = null,
    ) {
        $this->latitudeSigned      = $this->signedCoordinate($this->latitude, $this->latitudeRef, GpsLatLonRef::South, GpsLatLonRef::North);
        $this->longitudeSigned     = $this->signedCoordinate($this->longitude, $this->longitudeRef, GpsLatLonRef::West, GpsLatLonRef::East);
        $this->latitudeCoordinate  = GpsCoordinate::fromNullable($this->latitude, $this->latitudeRef, true);
        $this->longitudeCoordinate = GpsCoordinate::fromNullable($this->longitude, $this->longitudeRef, false);
    }

    /**
     * Applies the hemisphere reference to compute a signed coordinate.
     */
    private function signedCoordinate(?float $value, ?GpsLatLonRef $reference, GpsLatLonRef $negativeReference, GpsLatLonRef $positiveReference): ?float
    {
        if ($value === null || !$reference instanceof GpsLatLonRef) {
            return $value;
        }

        $magnitude = abs($value);

        if ($reference === $negativeReference) {
            return -$magnitude;
        }

        if ($reference === $positiveReference) {
            return $magnitude;
        }

        return $value;
    }
}
