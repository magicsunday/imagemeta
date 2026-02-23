<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Describes the GPS metadata at capture time including position, destination,
 * movement, timing, and measurement quality.
 */
final readonly class Gps
{
    /**
     * Creates a GPS metadata value object composed of domain sub-objects.
     *
     * @param GpsPosition|null    $position         Primary position including coordinates and altitude.
     * @param GpsDestination|null $destination      Destination coordinates, bearing, and distance.
     * @param GpsMovement|null    $movement         Speed, track, and image direction.
     * @param GpsTiming|null      $timing           Date, time, and combined UTC timestamp.
     * @param GpsMeasurement|null $measurement      Receiver status and measurement quality.
     * @param string|null         $version          GPS metadata version string (defaults to 2.4.0.0 when omitted).
     * @param string|null         $versionRaw       Raw GPS version payload without normalisation.
     * @param string|null         $processingMethod GPS processing method description.
     * @param string|null         $areaInformation  GPS area information description.
     */
    public function __construct(
        public ?GpsPosition $position = null,
        public ?GpsDestination $destination = null,
        public ?GpsMovement $movement = null,
        public ?GpsTiming $timing = null,
        public ?GpsMeasurement $measurement = null,
        public ?string $version = null,
        public ?string $versionRaw = null,
        public ?string $processingMethod = null,
        public ?string $areaInformation = null,
    ) {
    }
}
