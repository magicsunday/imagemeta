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
use MagicSunday\ImageMeta\Value\Contracts\GpsInterface;
use MagicSunday\ImageMeta\Value\GpsCoordinate;

/**
 * Describes the GPS position at capture time including navigation details.
 */
final readonly class Gps implements GpsInterface
{
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
        public ?DateTimeImmutable $timestamp = null,
        public ?int $differential = null,
        public ?float $horizontalPositioningError = null,
    ) {
    }

    public function latitude(): ?float
    {
        return $this->latitude;
    }

    public function longitude(): ?float
    {
        return $this->longitude;
    }

    public function latitudeReference(): ?string
    {
        return $this->latitudeRef;
    }

    /**
     * @deprecated Use latitudeReference() instead.
     */
    public function latitudeRef(): ?string
    {
        return $this->latitudeRef;
    }

    public function longitudeReference(): ?string
    {
        return $this->longitudeRef;
    }

    /**
     * @deprecated Use longitudeReference() instead.
     */
    public function longitudeRef(): ?string
    {
        return $this->longitudeRef;
    }

    public function altitude(): ?float
    {
        return $this->altitude;
    }

    public function altitudeReference(): ?int
    {
        return $this->altitudeRef;
    }

    /**
     * @deprecated Use altitudeReference() instead.
     */
    public function altitudeRef(): ?int
    {
        return $this->altitudeRef;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function versionRaw(): ?string
    {
        return $this->versionRaw;
    }

    public function satellites(): ?string
    {
        return $this->satellites;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function measureMode(): ?string
    {
        return $this->measureMode;
    }

    public function dilutionOfPrecision(): ?float
    {
        return $this->dop;
    }

    /**
     * @deprecated Use dilutionOfPrecision() instead.
     */
    public function dop(): ?float
    {
        return $this->dop;
    }

    public function speedReference(): ?string
    {
        return $this->speedRef;
    }

    /**
     * @deprecated Use speedReference() instead.
     */
    public function speedRef(): ?string
    {
        return $this->speedRef;
    }

    public function speedMs(): ?float
    {
        return $this->speedMs;
    }

    public function speedOriginalReference(): ?string
    {
        return $this->speedOriginalRef;
    }

    /**
     * @deprecated Use speedOriginalReference() instead.
     */
    public function speedOriginalRef(): ?string
    {
        return $this->speedOriginalRef;
    }

    public function speedOriginal(): ?float
    {
        return $this->speedOriginal;
    }

    public function trackReference(): ?string
    {
        return $this->trackRef;
    }

    /**
     * @deprecated Use trackReference() instead.
     */
    public function trackRef(): ?string
    {
        return $this->trackRef;
    }

    public function track(): ?float
    {
        return $this->track;
    }

    public function imageDirectionReference(): ?string
    {
        return $this->imageDirectionRef;
    }

    /**
     * @deprecated Use imageDirectionReference() instead.
     */
    public function imageDirectionRef(): ?string
    {
        return $this->imageDirectionRef;
    }

    public function imageDirection(): ?float
    {
        return $this->imageDirection;
    }

    public function mapDatum(): ?string
    {
        return $this->mapDatum;
    }

    public function destinationLatitudeReference(): ?string
    {
        return $this->destinationLatitudeRef;
    }

    /**
     * @deprecated Use destinationLatitudeReference() instead.
     */
    public function destinationLatitudeRef(): ?string
    {
        return $this->destinationLatitudeRef;
    }

    public function destinationLatitude(): ?float
    {
        return $this->destinationLatitude;
    }

    public function destinationLongitudeReference(): ?string
    {
        return $this->destinationLongitudeRef;
    }

    /**
     * @deprecated Use destinationLongitudeReference() instead.
     */
    public function destinationLongitudeRef(): ?string
    {
        return $this->destinationLongitudeRef;
    }

    public function destinationLongitude(): ?float
    {
        return $this->destinationLongitude;
    }

    public function destinationBearingReference(): ?string
    {
        return $this->destinationBearingRef;
    }

    /**
     * @deprecated Use destinationBearingReference() instead.
     */
    public function destinationBearingRef(): ?string
    {
        return $this->destinationBearingRef;
    }

    public function destinationBearing(): ?float
    {
        return $this->destinationBearing;
    }

    public function destinationDistanceReference(): ?string
    {
        return $this->destinationDistanceRef;
    }

    /**
     * @deprecated Use destinationDistanceReference() instead.
     */
    public function destinationDistanceRef(): ?string
    {
        return $this->destinationDistanceRef;
    }

    public function destinationDistanceMetres(): ?float
    {
        return $this->destinationDistanceMetres;
    }

    public function destinationDistanceOriginalReference(): ?string
    {
        return $this->destinationDistanceOriginalRef;
    }

    /**
     * @deprecated Use destinationDistanceOriginalReference() instead.
     */
    public function destinationDistanceOriginalRef(): ?string
    {
        return $this->destinationDistanceOriginalRef;
    }

    public function destinationDistanceOriginal(): ?float
    {
        return $this->destinationDistanceOriginal;
    }

    public function processingMethod(): ?string
    {
        return $this->processingMethod;
    }

    public function areaInformation(): ?string
    {
        return $this->areaInformation;
    }

    public function date(): ?string
    {
        return $this->date;
    }

    public function dateRaw(): ?string
    {
        return $this->dateRaw;
    }

    public function time(): ?string
    {
        return $this->time;
    }

    /**
     * Returns the combined capture timestamp normalised to UTC.
     */
    public function timestamp(): ?DateTimeImmutable
    {
        if ($this->timestamp === null) {
            return null;
        }

        if ($this->timestamp->getTimezone()->getName() === 'UTC') {
            return $this->timestamp;
        }

        return $this->timestamp->setTimezone(new DateTimeZone('UTC'));
    }

    public function differential(): ?int
    {
        return $this->differential;
    }

    public function horizontalPositioningError(): ?float
    {
        return $this->horizontalPositioningError;
    }

    public function latitudeSigned(): ?float
    {
        return $this->signedCoordinate($this->latitude, $this->latitudeRef, 'S', 'N');
    }

    public function longitudeSigned(): ?float
    {
        return $this->signedCoordinate($this->longitude, $this->longitudeRef, 'W', 'E');
    }

    public function latitudeCoordinate(): ?GpsCoordinate
    {
        if ($this->latitude === null) {
            return null;
        }

        return new GpsCoordinate($this->latitude, $this->latitudeRef, true);
    }

    public function longitudeCoordinate(): ?GpsCoordinate
    {
        if ($this->longitude === null) {
            return null;
        }

        return new GpsCoordinate($this->longitude, $this->longitudeRef, false);
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
