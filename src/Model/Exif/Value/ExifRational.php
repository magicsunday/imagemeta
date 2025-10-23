<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif\Value;

/**
 * Represents a single TIFF rational value.
 */
final readonly class ExifRational
{
    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {
    }

    /**
     * Returns the rational pair as a two element array.
     *
     * @return array{0:int,1:int}
     */
    public function toArray(): array
    {
        return [$this->numerator, $this->denominator];
    }

    /**
     * Converts the rational to a floating point value.
     *
     * @return float|null
     */
    public function asFloat(): ?float
    {
        if ($this->denominator === 0) {
            return null;
        }

        return $this->numerator / $this->denominator;
    }
}
