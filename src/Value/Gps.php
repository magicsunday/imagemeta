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
    public readonly ?DateTimeImmutable $timestamp;

    public readonly ?float $latitudeSigned;

    public readonly ?float $longitudeSigned;

    public readonly ?GpsCoordinate $latitudeCoordinate;

    public readonly ?GpsCoordinate $longitudeCoordinate;

    public readonly ?GpsCoordinate $destinationLatitudeCoordinate;

    public readonly ?GpsCoordinate $destinationLongitudeCoordinate;

    /**
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
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $latitudeRef = null,
        public readonly ?string $longitudeRef = null,
        public readonly ?float $altitude = null,
        public readonly ?int $altitudeRef = null,
        public readonly ?string $version = null,
        public readonly ?string $versionRaw = null,
        public readonly ?string $satellites = null,
        public readonly ?string $status = null,
        public readonly ?string $measureMode = null,
        public readonly ?float $dop = null,
        public readonly ?string $speedRef = null,
        public readonly ?float $speedMs = null,
        public readonly ?string $speedOriginalRef = null,
        public readonly ?float $speedOriginal = null,
        public readonly ?string $trackRef = null,
        public readonly ?float $track = null,
        public readonly ?string $imageDirectionRef = null,
        public readonly ?float $imageDirection = null,
        public readonly ?string $mapDatum = null,
        public readonly ?string $destinationLatitudeRef = null,
        public readonly ?float $destinationLatitude = null,
        public readonly ?string $destinationLongitudeRef = null,
        public readonly ?float $destinationLongitude = null,
        public readonly ?string $destinationBearingRef = null,
        public readonly ?float $destinationBearing = null,
        public readonly ?string $destinationDistanceRef = null,
        public readonly ?float $destinationDistanceMetres = null,
        public readonly ?string $destinationDistanceOriginalRef = null,
        public readonly ?float $destinationDistanceOriginal = null,
        public readonly ?string $processingMethod = null,
        public readonly ?string $areaInformation = null,
        public readonly ?string $date = null,
        public readonly ?string $dateRaw = null,
        public readonly ?string $time = null,
        ?DateTimeImmutable $timestamp = null,
        public readonly ?int $differential = null,
        public readonly ?float $horizontalPositioningError = null,
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
        if ($timestamp === null) {
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
