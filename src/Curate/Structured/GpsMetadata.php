<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Value\Gps as GpsValue;

/**
 * Provides a higher level view on geographic positioning metadata.
 */
final readonly class GpsMetadata
{
    public function __construct(public GpsValue $gps)
    {
    }

    public function gps(): GpsValue
    {
        return $this->gps;
    }

    public function latitude(): ?GpsCoordinate
    {
        if ($this->gps->latitude === null) {
            return null;
        }

        return new GpsCoordinate($this->gps->latitude, $this->gps->latitudeRef);
    }

    public function longitude(): ?GpsCoordinate
    {
        if ($this->gps->longitude === null) {
            return null;
        }

        return new GpsCoordinate($this->gps->longitude, $this->gps->longitudeRef);
    }

    public function altitude(): ?float
    {
        return $this->gps->altitude;
    }

    public function altitudeReference(): ?int
    {
        return $this->gps->altitudeRef;
    }

    public function version(): ?string
    {
        return $this->gps->version;
    }

    public function versionRaw(): ?string
    {
        return $this->gps->versionRaw;
    }

    public function satellites(): ?string
    {
        return $this->gps->satellites;
    }

    public function status(): ?string
    {
        return $this->gps->status;
    }

    public function measureMode(): ?string
    {
        return $this->gps->measureMode;
    }

    public function dilutionOfPrecision(): ?float
    {
        return $this->gps->dop;
    }

    public function speedReference(): ?string
    {
        return $this->gps->speedRef;
    }

    public function speedMs(): ?float
    {
        return $this->gps->speedMs;
    }

    public function speedOriginalReference(): ?string
    {
        return $this->gps->speedOriginalRef;
    }

    public function speedOriginal(): ?float
    {
        return $this->gps->speedOriginal;
    }

    public function trackReference(): ?string
    {
        return $this->gps->trackRef;
    }

    public function track(): ?float
    {
        return $this->gps->track;
    }

    public function imageDirectionReference(): ?string
    {
        return $this->gps->imageDirectionRef;
    }

    public function imageDirection(): ?float
    {
        return $this->gps->imageDirection;
    }

    public function mapDatum(): ?string
    {
        return $this->gps->mapDatum;
    }

    public function destinationLatitude(): ?GpsCoordinate
    {
        if ($this->gps->destinationLatitude === null) {
            return null;
        }

        return new GpsCoordinate($this->gps->destinationLatitude, $this->gps->destinationLatitudeRef);
    }

    public function destinationLongitude(): ?GpsCoordinate
    {
        if ($this->gps->destinationLongitude === null) {
            return null;
        }

        return new GpsCoordinate($this->gps->destinationLongitude, $this->gps->destinationLongitudeRef);
    }

    public function destinationBearingReference(): ?string
    {
        return $this->gps->destinationBearingRef;
    }

    public function destinationBearing(): ?float
    {
        return $this->gps->destinationBearing;
    }

    public function destinationDistanceReference(): ?string
    {
        return $this->gps->destinationDistanceRef;
    }

    public function destinationDistanceMetres(): ?float
    {
        return $this->gps->destinationDistanceMetres;
    }

    public function destinationDistanceOriginalReference(): ?string
    {
        return $this->gps->destinationDistanceOriginalRef;
    }

    public function destinationDistanceOriginal(): ?float
    {
        return $this->gps->destinationDistanceOriginal;
    }

    public function processingMethod(): ?string
    {
        return $this->gps->processingMethod;
    }

    public function areaInformation(): ?string
    {
        return $this->gps->areaInformation;
    }

    public function date(): ?string
    {
        return $this->gps->date;
    }

    public function dateRaw(): ?string
    {
        return $this->gps->dateRaw;
    }

    public function time(): ?string
    {
        return $this->gps->time;
    }

    public function timestamp(): ?DateTimeImmutable
    {
        return $this->gps->timestamp;
    }

    public function differential(): ?int
    {
        return $this->gps->differential;
    }

    public function horizontalPositioningError(): ?float
    {
        return $this->gps->horizontalPositioningError;
    }
}
