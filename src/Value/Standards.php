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
 * Captures version identifiers for the embedded metadata standards.
 */
final readonly class Standards
{
    /**
     * @param string|null $exifVersion       Normalised EXIF specification version (e.g. "3.00").
     * @param string|null $flashpixVersion   FlashPix specification version string.
     * @param list<int>|null $tiffEpStandardId TIFF/EP identifier bytes.
     */
    public function __construct(
        public ?string $exifVersion,
        public ?string $flashpixVersion,
        public ?array $tiffEpStandardId,
    ) {
    }
}
