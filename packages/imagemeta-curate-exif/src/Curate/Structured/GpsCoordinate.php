<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

/**
 * Represents a GPS coordinate paired with its hemisphere reference.
 */
final readonly class GpsCoordinate
{
    public function __construct(
        private ?float $value,
        private ?string $reference,
    ) {
    }

    public function toFloat(): ?float
    {
        return $this->value;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }
}
