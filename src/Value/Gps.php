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

/**
 * Describes the GPS position at capture time including navigation details.
 */
final readonly class Gps
{
    /**
     * @param float|null             $latitude                   Latitude in decimal degrees.
     * @param float|null             $longitude                  Longitude in decimal degrees.
     * @param string|null            $latitudeRef                Latitude hemisphere reference (N/S).
     * @param string|null            $longitudeRef               Longitude hemisphere reference (E/W).
     * @param float|null             $altitude                   Altitude in metres relative to sea level.
     * @param int|null               $altitudeRef                Altitude reference (0 = above, 1 = below sea level).
     * @param string|null            $version                    GPS metadata version string.
     * @param string|null            $satellites                 Satellites used for measurement.
     * @param string|null            $status                     Receiver status at capture time.
     * @param string|null            $measureMode                Measurement mode (2 = 2D, 3 = 3D).
     * @param float|null             $dop                        Dilution of precision.
     * @param string|null            $speedRef                   Speed reference unit (K/M/N).
     * @param float|null             $speedMs                    Ground speed in metres per second.
     * @param string|null            $speedOriginalRef           Original speed reference unit.
     * @param float|null             $speedOriginal              Raw ground speed in the original unit.
     * @param string|null            $trackRef                   Course over ground reference (T/M).
     * @param float|null             $track                      Course over ground in degrees.
     * @param string|null            $imageDirectionRef          Image direction reference (T/M).
     * @param float|null             $imageDirection             Image direction in degrees.
     * @param string|null            $mapDatum                   Geodetic survey data used.
     * @param string|null            $destinationLatitudeRef     Destination latitude reference.
     * @param float|null             $destinationLatitude        Destination latitude in decimal degrees.
     * @param string|null            $destinationLongitudeRef    Destination longitude reference.
     * @param float|null             $destinationLongitude       Destination longitude in decimal degrees.
     * @param string|null            $destinationBearingRef      Destination bearing reference (T/M).
     * @param float|null             $destinationBearing         Destination bearing in degrees.
     * @param string|null            $destinationDistanceRef     Destination distance reference (K/M/N).
     * @param float|null             $destinationDistanceMetres  Destination distance in metres.
     * @param string|null            $destinationDistanceOriginalRef Destination distance reference in original unit.
     * @param float|null             $destinationDistanceOriginal Raw destination distance in the original unit.
     * @param string|null            $processingMethod           GPS processing method description.
     * @param string|null            $areaInformation            GPS area information description.
     * @param string|null            $date                       GPS date stamp in ISO 8601 calendar format.
     * @param string|null            $time                       GPS time stamp in HH:MM:SS(.sss) format.
     * @param DateTimeImmutable|null $timestamp                  Combined UTC timestamp when available.
     * @param int|null               $differential               Differential GPS indicator.
     * @param float|null             $horizontalPositioningError Horizontal positioning error in metres.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $latitudeRef = null,
        public ?string $longitudeRef = null,
        public ?float $altitude = null,
        public ?int $altitudeRef = null,
        public ?string $version = null,
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
        public ?string $time = null,
        public ?DateTimeImmutable $timestamp = null,
        public ?int $differential = null,
        public ?float $horizontalPositioningError = null,
    ) {
    }
}
