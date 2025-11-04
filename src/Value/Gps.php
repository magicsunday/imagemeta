<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Describes the GPS position at capture time including navigation details.
 */
final readonly class Gps
{
    public ?DateTimeImmutable $timestamp;

    public ?float $latitudeSigned;

    public ?float $longitudeSigned;

    public ?GpsCoordinate $latitudeCoordinate;

    public ?GpsCoordinate $longitudeCoordinate;

    public ?GpsCoordinate $destinationLatitudeCoordinate;

    public ?GpsCoordinate $destinationLongitudeCoordinate;

    /**
     * Creates a GPS location and navigation metadata value object.
     *
     * @param float|null             $latitude                       Latitude in decimal degrees.
     * @param float|null             $longitude                      Longitude in decimal degrees.
     * @param string|null            $latitudeRef                    Latitude hemisphere reference (N/S).
     * @param string|null            $longitudeRef                   Longitude hemisphere reference (E/W).
     * @param float|null             $altitude                       Altitude in metres relative to sea level.
     * @param int|null               $altitudeRef                    Altitude reference (0 = above, 1 = below sea level).
     * @param string|null            $version                        GPS metadata version string (defaults to 2.0.0.0 when omitted).
     * @param string|null            $versionRaw                     Raw GPS version payload without normalisation.
     * @param string|null            $satellites                     Satellites used for measurement.
     * @param string|null            $status                         Receiver status at capture time.
     * @param string|null            $measureMode                    Measurement mode (2 = 2D, 3 = 3D).
     * @param float|null             $dop                            Dilution of precision.
     * @param string|null            $speedRef                       Speed reference unit (K/M/N).
     * @param float|null             $speedMs                        Ground speed in metres per second.
     * @param string|null            $speedOriginalRef               Original speed reference unit.
     * @param float|null             $speedOriginal                  Raw ground speed in the original unit.
     * @param string|null            $trackRef                       Course over ground reference (T/M).
     * @param float|null             $track                          Course over ground in degrees.
     * @param string|null            $imageDirectionRef              Image direction reference (T/M).
     * @param float|null             $imageDirection                 Image direction in degrees.
     * @param string|null            $mapDatum                       Geodetic survey data used.
     * @param string|null            $destinationLatitudeRef         Destination latitude reference.
     * @param float|null             $destinationLatitude            Destination latitude in decimal degrees.
     * @param string|null            $destinationLongitudeRef        Destination longitude reference.
     * @param float|null             $destinationLongitude           Destination longitude in decimal degrees.
     * @param string|null            $destinationBearingRef          Destination bearing reference (T/M).
     * @param float|null             $destinationBearing             Destination bearing in degrees.
     * @param string|null            $destinationDistanceRef         Destination distance reference (K/M/N).
     * @param float|null             $destinationDistanceMetres      Destination distance in metres.
     * @param string|null            $destinationDistanceOriginalRef Destination distance reference in original unit.
     * @param float|null             $destinationDistanceOriginal    Raw destination distance in the original unit.
     * @param string|null            $processingMethod               GPS processing method description.
     * @param string|null            $areaInformation                GPS area information description.
     * @param string|null            $date                           GPS date stamp in ISO 8601 calendar format.
     * @param string|null            $dateRaw                        Raw GPS date payload without normalisation.
     * @param string|null            $time                           GPS time stamp in HH:MM:SS(.sss) format.
     * @param DateTimeImmutable|null $timestamp                      Combined UTC timestamp when available.
     * @param int|null               $differential                   Differential GPS indicator.
     * @param float|null             $horizontalPositioningError     Horizontal positioning error in metres.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $latitudeRef = null,
        public ?string $longitudeRef = null,
        public ?float $altitude = null,
        public ?int $altitudeRef = null,
        public ?string $version = null,
        public ?string $versionRaw = null,
        public ?string $satellites = null,
        public ?string $status = null,
        public ?string $measureMode = null,
        public ?float $dop = null,
        public ?string $speedRef = null,
        public ?float $speedMs = null,
        public ?string $speedOriginalRef = null,
        public ?float $speedOriginal = null,
        public ?string $trackRef = null,
        public ?float $track = null,
        public ?string $imageDirectionRef = null,
        public ?float $imageDirection = null,
        public ?string $mapDatum = null,
        public ?string $destinationLatitudeRef = null,
        public ?float $destinationLatitude = null,
        public ?string $destinationLongitudeRef = null,
        public ?float $destinationLongitude = null,
        public ?string $destinationBearingRef = null,
        public ?float $destinationBearing = null,
        public ?string $destinationDistanceRef = null,
        public ?float $destinationDistanceMetres = null,
        public ?string $destinationDistanceOriginalRef = null,
        public ?float $destinationDistanceOriginal = null,
        public ?string $processingMethod = null,
        public ?string $areaInformation = null,
        public ?string $date = null,
        public ?string $dateRaw = null,
        public ?string $time = null,
        ?DateTimeImmutable $timestamp = null,
        public ?int $differential = null,
        public ?float $horizontalPositioningError = null,
    ) {
        $this->timestamp                      = $this->normaliseTimestamp($timestamp);
        $this->latitudeSigned                 = $this->signedCoordinate($this->latitude, $this->latitudeRef, 'S', 'N');
        $this->longitudeSigned                = $this->signedCoordinate($this->longitude, $this->longitudeRef, 'W', 'E');
        $this->latitudeCoordinate             = $this->createCoordinate($this->latitude, $this->latitudeRef, true);
        $this->longitudeCoordinate            = $this->createCoordinate($this->longitude, $this->longitudeRef, false);
        $this->destinationLatitudeCoordinate  = $this->createCoordinate($this->destinationLatitude, $this->destinationLatitudeRef, true);
        $this->destinationLongitudeCoordinate = $this->createCoordinate($this->destinationLongitude, $this->destinationLongitudeRef, false);
    }

    private function normaliseTimestamp(?DateTimeImmutable $timestamp): ?DateTimeImmutable
    {
        if (!$timestamp instanceof DateTimeImmutable) {
            return null;
        }

        if ($timestamp->getTimezone()->getName() === 'UTC') {
            return $timestamp;
        }

        return $timestamp->setTimezone(new DateTimeZone('UTC'));
    }

    private function createCoordinate(?float $value, ?string $reference, bool $isLatitude): ?GpsCoordinate
    {
        if ($value === null) {
            return null;
        }

        return new GpsCoordinate($value, $reference, $isLatitude);
    }

    private function signedCoordinate(?float $value, ?string $reference, string $negativeReference, string $positiveReference): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($reference === null) {
            return $value;
        }

        $normalizedReference = strtoupper($reference);
        $normalizedReference = $normalizedReference[0] ?? '';

        if ($normalizedReference === '') {
            return $value;
        }

        $magnitude = abs($value);

        if ($normalizedReference === $negativeReference) {
            return -$magnitude;
        }

        if ($normalizedReference === $positiveReference) {
            return $magnitude;
        }

        return $value;
    }
}
