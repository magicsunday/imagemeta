<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;

/**
 * Describes the GPS destination including coordinates, bearing, and distance.
 */
final readonly class GpsDestination
{
    public ?GpsCoordinate $latitudeCoordinate;

    public ?GpsCoordinate $longitudeCoordinate;

    /**
     * @param float|null           $latitude            Destination latitude in decimal degrees.
     * @param GpsLatLonRef|null    $latitudeRef         Destination latitude reference (N/S).
     * @param float|null           $longitude           Destination longitude in decimal degrees.
     * @param GpsLatLonRef|null    $longitudeRef        Destination longitude reference (E/W).
     * @param GpsDirectionRef|null $bearingRef          Destination bearing reference (T/M).
     * @param float|null           $bearing             Destination bearing in degrees.
     * @param GpsDistanceRef|null  $distanceRef         Destination distance reference (K/M/N).
     * @param float|null           $distanceMetres      Destination distance in metres.
     * @param GpsDistanceRef|null  $distanceOriginalRef Destination distance reference in original unit.
     * @param float|null           $distanceOriginal    Raw destination distance in the original unit.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?GpsLatLonRef $latitudeRef = null,
        public ?float $longitude = null,
        public ?GpsLatLonRef $longitudeRef = null,
        public ?GpsDirectionRef $bearingRef = null,
        public ?float $bearing = null,
        public ?GpsDistanceRef $distanceRef = null,
        public ?float $distanceMetres = null,
        public ?GpsDistanceRef $distanceOriginalRef = null,
        public ?float $distanceOriginal = null,
    ) {
        $this->latitudeCoordinate  = GpsCoordinate::fromNullable($this->latitude, $this->latitudeRef, true);
        $this->longitudeCoordinate = GpsCoordinate::fromNullable($this->longitude, $this->longitudeRef, false);
    }
}
