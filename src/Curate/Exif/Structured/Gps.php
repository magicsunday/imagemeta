<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Structured\GpsCoordinate;
use MagicSunday\ImageMeta\Value\Gps as GpsValue;

/**
 * Offers EXIF GPS metadata without external augmentation.
 */
final readonly class Gps
{
    public ?GpsCoordinate $latitude;

    public ?GpsCoordinate $longitude;

    public ?float $altitude;

    public ?int $altitudeReference;

    public ?string $version;

    public ?string $versionRaw;

    public ?string $satellites;

    public ?string $status;

    public ?string $measureMode;

    public ?float $dilutionOfPrecision;

    public ?string $speedReference;

    public ?float $speedMs;

    public ?string $speedOriginalReference;

    public ?float $speedOriginal;

    public ?string $trackReference;

    public ?float $track;

    public ?string $imageDirectionReference;

    public ?float $imageDirection;

    public ?string $mapDatum;

    public ?GpsCoordinate $destinationLatitude;

    public ?GpsCoordinate $destinationLongitude;

    public ?string $destinationBearingReference;

    public ?float $destinationBearing;

    public ?string $destinationDistanceReference;

    public ?float $destinationDistanceMetres;

    public ?string $destinationDistanceOriginalReference;

    public ?float $destinationDistanceOriginal;

    public ?string $processingMethod;

    public ?string $areaInformation;

    public ?string $date;

    public ?string $dateRaw;

    public ?string $time;

    public ?DateTimeImmutable $timestamp;

    public ?int $differential;

    public ?float $horizontalPositioningError;

    /**
     * @param GpsValue $gps Raw GPS value object containing the EXIF coordinates and references plus already parsed time data.
     */
    public function __construct(GpsValue $gps)
    {
        // Wrap EXIF coordinate values with their hemisphere reference so that signed decimal degrees stay consistent.
        $this->latitude                = $gps->latitude !== null ? new GpsCoordinate($gps->latitude, $gps->latitudeRef) : null;
        $this->longitude               = $gps->longitude !== null ? new GpsCoordinate($gps->longitude, $gps->longitudeRef) : null;
        $this->altitude                = $gps->altitude;
        $this->altitudeReference       = $gps->altitudeRef;
        $this->version                 = $gps->version;
        $this->versionRaw              = $gps->versionRaw;
        $this->satellites              = $gps->satellites;
        $this->status                  = $gps->status;
        $this->measureMode             = $gps->measureMode;
        $this->dilutionOfPrecision     = $gps->dop;
        $this->speedReference          = $gps->speedRef;
        $this->speedMs                 = $gps->speedMs;
        $this->speedOriginalReference  = $gps->speedOriginalRef;
        $this->speedOriginal           = $gps->speedOriginal;
        $this->trackReference          = $gps->trackRef;
        $this->track                   = $gps->track;
        $this->imageDirectionReference = $gps->imageDirectionRef;
        $this->imageDirection          = $gps->imageDirection;
        $this->mapDatum                = $gps->mapDatum;
        $this->destinationLatitude     = $gps->destinationLatitude !== null
            ? new GpsCoordinate($gps->destinationLatitude, $gps->destinationLatitudeRef)
            : null;
        $this->destinationLongitude = $gps->destinationLongitude !== null
            ? new GpsCoordinate($gps->destinationLongitude, $gps->destinationLongitudeRef)
            : null;
        $this->destinationBearingReference          = $gps->destinationBearingRef;
        $this->destinationBearing                   = $gps->destinationBearing;
        $this->destinationDistanceReference         = $gps->destinationDistanceRef;
        $this->destinationDistanceMetres            = $gps->destinationDistanceMetres;
        $this->destinationDistanceOriginalReference = $gps->destinationDistanceOriginalRef;
        $this->destinationDistanceOriginal          = $gps->destinationDistanceOriginal;
        $this->processingMethod                     = $gps->processingMethod;
        $this->areaInformation                      = $gps->areaInformation;
        $this->date                                 = $gps->date;
        $this->dateRaw                              = $gps->dateRaw;
        $this->time                                 = $gps->time;
        $this->timestamp                            = $gps->timestamp;
        $this->differential                         = $gps->differential;
        $this->horizontalPositioningError           = $gps->horizontalPositioningError;
    }

    public function latitude(): ?GpsCoordinate
    {
        return $this->latitude;
    }

    public function longitude(): ?GpsCoordinate
    {
        return $this->longitude;
    }

    public function altitude(): ?float
    {
        return $this->altitude;
    }

    public function altitudeReference(): ?int
    {
        return $this->altitudeReference;
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
        return $this->dilutionOfPrecision;
    }

    public function speedReference(): ?string
    {
        return $this->speedReference;
    }

    public function speedMs(): ?float
    {
        return $this->speedMs;
    }

    public function speedOriginalReference(): ?string
    {
        return $this->speedOriginalReference;
    }

    public function speedOriginal(): ?float
    {
        return $this->speedOriginal;
    }

    public function trackReference(): ?string
    {
        return $this->trackReference;
    }

    public function track(): ?float
    {
        return $this->track;
    }

    public function imageDirectionReference(): ?string
    {
        return $this->imageDirectionReference;
    }

    public function imageDirection(): ?float
    {
        return $this->imageDirection;
    }

    public function mapDatum(): ?string
    {
        return $this->mapDatum;
    }

    public function destinationLatitude(): ?GpsCoordinate
    {
        return $this->destinationLatitude;
    }

    public function destinationLongitude(): ?GpsCoordinate
    {
        return $this->destinationLongitude;
    }

    public function destinationBearingReference(): ?string
    {
        return $this->destinationBearingReference;
    }

    public function destinationBearing(): ?float
    {
        return $this->destinationBearing;
    }

    public function destinationDistanceReference(): ?string
    {
        return $this->destinationDistanceReference;
    }

    public function destinationDistanceMetres(): ?float
    {
        return $this->destinationDistanceMetres;
    }

    public function destinationDistanceOriginalReference(): ?string
    {
        return $this->destinationDistanceOriginalReference;
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

    public function timestamp(): ?DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function differential(): ?int
    {
        return $this->differential;
    }

    public function horizontalPositioningError(): ?float
    {
        return $this->horizontalPositioningError;
    }
}
