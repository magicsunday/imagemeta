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

interface GpsInterface
{
    public function latitude(): ?float;

    public function longitude(): ?float;

    public function latitudeRef(): ?string;

    public function longitudeRef(): ?string;

    public function altitude(): ?float;

    public function altitudeRef(): ?int;

    public function version(): ?string;

    public function versionRaw(): ?string;

    public function satellites(): ?string;

    public function status(): ?string;

    public function measureMode(): ?string;

    public function dop(): ?float;

    public function speedRef(): ?string;

    public function speedMs(): ?float;

    public function speedOriginalRef(): ?string;

    public function speedOriginal(): ?float;

    public function trackRef(): ?string;

    public function track(): ?float;

    public function imageDirectionRef(): ?string;

    public function imageDirection(): ?float;

    public function mapDatum(): ?string;

    public function destinationLatitudeRef(): ?string;

    public function destinationLatitude(): ?float;

    public function destinationLongitudeRef(): ?string;

    public function destinationLongitude(): ?float;

    public function destinationBearingRef(): ?string;

    public function destinationBearing(): ?float;

    public function destinationDistanceRef(): ?string;

    public function destinationDistanceMetres(): ?float;

    public function destinationDistanceOriginalRef(): ?string;

    public function destinationDistanceOriginal(): ?float;

    public function processingMethod(): ?string;

    public function areaInformation(): ?string;

    public function date(): ?string;

    public function dateRaw(): ?string;

    public function time(): ?string;

    public function timestamp(): ?DateTimeImmutable;

    public function differential(): ?int;

    public function horizontalPositioningError(): ?float;
}
