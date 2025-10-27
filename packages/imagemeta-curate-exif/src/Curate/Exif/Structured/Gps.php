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

    public function __construct(GpsValue $gps)
    {
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
}
