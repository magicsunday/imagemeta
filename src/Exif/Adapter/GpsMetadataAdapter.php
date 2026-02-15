<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Adapter;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;

/**
 * Provides GPS-domain accessors on top of ParsedExif.
 */
final readonly class GpsMetadataAdapter
{
    public function __construct(private ParsedExif $parsedExif)
    {
    }

    /**
     * @return array<string, string|int|float|DateTimeImmutable|null>
     */
    public function all(): array
    {
        return $this->parsedExif->gps();
    }

    public function latitude(): ?float
    {
        $gps = $this->parsedExif->gps();

        return $gps['lat'];
    }

    public function longitude(): ?float
    {
        $gps = $this->parsedExif->gps();

        return $gps['lon'];
    }

    public function altitude(): ?float
    {
        $gps = $this->parsedExif->gps();

        return $gps['alt'];
    }

    public function timestamp(): ?DateTimeImmutable
    {
        return $this->parsedExif->gpsTimestamp();
    }
}
