<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

/**
 * Represents a rational number stored in EXIF metadata.
 */
final readonly class ExifRational
{
    /**
     * @param int $numerator   Numerator component of the rational value.
     * @param int $denominator Denominator component of the rational value.
     */
    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {
    }
}
