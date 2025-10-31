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
use MagicSunday\ImageMeta\Value\Lens as LensValue;

/**
 * Provides lens information including derived optical characteristics.
 */
final readonly class LensMetadata
{
    public function __construct(
        public LensValue $lens,
        public Derived $derived,
    ) {
    }

    public function lens(): LensValue
    {
        return $this->lens;
    }

    public function derived(): Derived
    {
        return $this->derived;
    }

    public function equivalent35mm(): ?int
    {
        return $this->lens->focalLengthIn35mm ?? $this->derived->focalLength35mm;
    }
}
