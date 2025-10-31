<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Value\GpsCoordinate;

interface GpsInterface
{
    public function latitude(): ?float;

    public function longitude(): ?float;

    public function latitudeReference(): ?string;

    /**
     * @deprecated Use latitudeReference() instead.
     */
    public function latitudeRef(): ?string;

    public function longitudeReference(): ?string;

    /**
     * @deprecated Use longitudeReference() instead.
     */
    public function longitudeRef(): ?string;

    public function altitude(): ?float;

    public function altitudeReference(): ?int;

    /**
     * @deprecated Use altitudeReference() instead.
     */
    public function altitudeRef(): ?int;

    public function version(): ?string;

    public function versionRaw(): ?string;

    public function satellites(): ?string;

    public function status(): ?string;

    public function measureMode(): ?string;

    public function dilutionOfPrecision(): ?float;

    /**
     * @deprecated Use dilutionOfPrecision() instead.
     */
    public function dop(): ?float;

    public function speedReference(): ?string;

    /**
     * @deprecated Use speedReference() instead.
     */
    public function speedRef(): ?string;

    public function speedMs(): ?float;

    public function speedOriginalReference(): ?string;

    /**
     * @deprecated Use speedOriginalReference() instead.
     */
    public function speedOriginalRef(): ?string;

    public function speedOriginal(): ?float;

    public function trackReference(): ?string;

    /**
     * @deprecated Use trackReference() instead.
     */
    public function trackRef(): ?string;

    public function track(): ?float;

    public function imageDirectionReference(): ?string;

    /**
     * @deprecated Use imageDirectionReference() instead.
     */
    public function imageDirectionRef(): ?string;

    public function imageDirection(): ?float;

    public function mapDatum(): ?string;

    public function destinationLatitudeReference(): ?string;

    /**
     * @deprecated Use destinationLatitudeReference() instead.
     */
    public function destinationLatitudeRef(): ?string;

    public function destinationLatitude(): ?float;

    public function destinationLongitudeReference(): ?string;

    /**
     * @deprecated Use destinationLongitudeReference() instead.
     */
    public function destinationLongitudeRef(): ?string;

    public function destinationLongitude(): ?float;

    public function destinationBearingReference(): ?string;

    /**
     * @deprecated Use destinationBearingReference() instead.
     */
    public function destinationBearingRef(): ?string;

    public function destinationBearing(): ?float;

    public function destinationDistanceReference(): ?string;

    /**
     * @deprecated Use destinationDistanceReference() instead.
     */
    public function destinationDistanceRef(): ?string;

    public function destinationDistanceMetres(): ?float;

    public function destinationDistanceOriginalReference(): ?string;

    /**
     * @deprecated Use destinationDistanceOriginalReference() instead.
     */
    public function destinationDistanceOriginalRef(): ?string;

    public function destinationDistanceOriginal(): ?float;

    public function processingMethod(): ?string;

    public function areaInformation(): ?string;

    public function date(): ?string;

    public function dateRaw(): ?string;

    public function time(): ?string;

    /**
     * Returns the combined capture timestamp in UTC.
     */
    public function timestamp(): ?DateTimeImmutable;

    public function differential(): ?int;

    public function horizontalPositioningError(): ?float;

    public function latitudeSigned(): ?float;

    public function longitudeSigned(): ?float;

    public function latitudeCoordinate(): ?GpsCoordinate;

    public function longitudeCoordinate(): ?GpsCoordinate;
}
