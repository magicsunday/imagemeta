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
 * Describes the GPS position at capture time.
 */
final readonly class Gps
{
    /**
     * @param float|null $latitude  Latitude in decimal degrees.
     * @param float|null $longitude Longitude in decimal degrees.
     * @param float|null $altitude  Altitude in metres relative to sea level.
     */
    public function __construct(
        public ?float $latitude,
        public ?float $longitude,
        public ?float $altitude,
    ) {
    }
}
