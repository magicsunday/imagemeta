<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Exposure as ExposureValue;

/**
 * Exposure measurements merged with derived exposure metrics.
 */
final readonly class ExposureMetadata
{
    public function __construct(
        public ExposureValue $exposure,
        public Derived $derived,
    ) {
    }

    public function exposure(): ExposureValue
    {
        return $this->exposure;
    }

    public function derived(): Derived
    {
        return $this->derived;
    }
}
