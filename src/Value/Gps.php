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
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;

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
     * @param GpsLatLonRef|null      $latitudeRef                    Latitude hemisphere reference (N/S).
     * @param GpsLatLonRef|null      $longitudeRef                   Longitude hemisphere reference (E/W).
     * @param float|null             $altitude                       Altitude in metres relative to sea level.
     * @param GpsAltitudeRef|null    $altitudeRef                    Altitude reference (above/below sea level).
     * @param string|null            $version                        GPS metadata version string (defaults to 2.0.0.0 when omitted).
     * @param string|null            $versionRaw                     Raw GPS version payload without normalisation.
     * @param string|null            $satellites                     Satellites used for measurement.
     * @param GpsStatus|null         $status                         Receiver status at capture time.
     * @param GpsMeasureMode|null    $measureMode                    Measurement mode (2D/3D).
     * @param float|null             $dop                            Dilution of precision.
     * @param GpsSpeedRef|null       $speedRef                       Speed reference unit (K/M/N).
     * @param float|null             $speedMs                        Ground speed in metres per second.
     * @param GpsSpeedRef|null       $speedOriginalRef               Original speed reference unit.
     * @param float|null             $speedOriginal                  Raw ground speed in the original unit.
     * @param GpsDirectionRef|null   $trackRef                       Course over ground reference (T/M).
     * @param float|null             $track                          Course over ground in degrees.
     * @param GpsDirectionRef|null   $imageDirectionRef              Image direction reference (T/M).
     * @param float|null             $imageDirection                 Image direction in degrees.
     * @param string|null            $mapDatum                       Geodetic survey data used.
     * @param GpsLatLonRef|null      $destinationLatitudeRef         Destination latitude reference (N/S).
     * @param float|null             $destinationLatitude            Destination latitude in decimal degrees.
     * @param GpsLatLonRef|null      $destinationLongitudeRef        Destination longitude reference (E/W).
     * @param float|null             $destinationLongitude           Destination longitude in decimal degrees.
     * @param GpsDirectionRef|null   $destinationBearingRef          Destination bearing reference (T/M).
     * @param float|null             $destinationBearing             Destination bearing in degrees.
     * @param GpsDistanceRef|null    $destinationDistanceRef         Destination distance reference (K/M/N).
     * @param float|null             $destinationDistanceMetres      Destination distance in metres.
     * @param GpsDistanceRef|null    $destinationDistanceOriginalRef Destination distance reference in original unit.
     * @param float|null             $destinationDistanceOriginal    Raw destination distance in the original unit.
     * @param string|null            $processingMethod               GPS processing method description.
     * @param string|null            $areaInformation                GPS area information description.
     * @param string|null            $date                           GPS date stamp in ISO 8601 calendar format.
     * @param string|null            $dateRaw                        Raw GPS date payload without normalisation.
     * @param string|null            $time                           GPS time stamp in HH:MM:SS(.sss) format.
     * @param DateTimeImmutable|null $timestamp                      Combined UTC timestamp when available.
     * @param GpsDifferential|null   $differential                   Differential GPS indicator.
     * @param float|null             $horizontalPositioningError     Horizontal positioning error in metres.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?GpsLatLonRef $latitudeRef = null,
        public ?GpsLatLonRef $longitudeRef = null,
        public ?float $altitude = null,
        public ?GpsAltitudeRef $altitudeRef = null,
        public ?string $version = null,
        public ?string $versionRaw = null,
        public ?string $satellites = null,
        public ?GpsStatus $status = null,
        public ?GpsMeasureMode $measureMode = null,
        public ?float $dop = null,
        public ?GpsSpeedRef $speedRef = null,
        public ?float $speedMs = null,
        public ?GpsSpeedRef $speedOriginalRef = null,
        public ?float $speedOriginal = null,
        public ?GpsDirectionRef $trackRef = null,
        public ?float $track = null,
        public ?GpsDirectionRef $imageDirectionRef = null,
        public ?float $imageDirection = null,
        public ?string $mapDatum = null,
        public ?GpsLatLonRef $destinationLatitudeRef = null,
        public ?float $destinationLatitude = null,
        public ?GpsLatLonRef $destinationLongitudeRef = null,
        public ?float $destinationLongitude = null,
        public ?GpsDirectionRef $destinationBearingRef = null,
        public ?float $destinationBearing = null,
        public ?GpsDistanceRef $destinationDistanceRef = null,
        public ?float $destinationDistanceMetres = null,
        public ?GpsDistanceRef $destinationDistanceOriginalRef = null,
        public ?float $destinationDistanceOriginal = null,
        public ?string $processingMethod = null,
        public ?string $areaInformation = null,
        public ?string $date = null,
        public ?string $dateRaw = null,
        public ?string $time = null,
        ?DateTimeImmutable $timestamp = null,
        public ?GpsDifferential $differential = null,
        public ?float $horizontalPositioningError = null,
    ) {
        $this->timestamp                      = $this->normaliseTimestamp($timestamp);
        $this->latitudeSigned                 = $this->signedCoordinate($this->latitude, $this->latitudeRef, GpsLatLonRef::SOUTH, GpsLatLonRef::NORTH);
        $this->longitudeSigned                = $this->signedCoordinate($this->longitude, $this->longitudeRef, GpsLatLonRef::WEST, GpsLatLonRef::EAST);
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

    private function createCoordinate(?float $value, ?GpsLatLonRef $reference, bool $isLatitude): ?GpsCoordinate
    {
        if ($value === null) {
            return null;
        }

        return new GpsCoordinate($value, $reference?->value, $isLatitude);
    }

    private function signedCoordinate(?float $value, ?GpsLatLonRef $reference, GpsLatLonRef $negativeReference, GpsLatLonRef $positiveReference): ?float
    {
        if ($value === null || $reference === null) {
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
