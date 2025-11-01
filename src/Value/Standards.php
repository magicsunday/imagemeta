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
 * Captures version identifiers and derived capability information for metadata standards.
 */
final readonly class Standards
{
    /**
     * @param string|null    $exifVersion          Normalised EXIF specification version (e.g. "3.00").
     * @param string|null    $profile              Derived EXIF capability profile (e.g. "3.0").
     * @param string|null    $flashpixVersion      FlashPix specification version string.
     * @param list<int>|null $tiffEpStandardId     TIFF/EP identifier bytes.
     * @param string|null    $tiffEpStandardString Human readable TIFF/EP identifier.
     */
    public function __construct(
        public readonly ?string $exifVersion,
        public readonly ?string $profile,
        public readonly ?string $flashpixVersion,
        public readonly ?array $tiffEpStandardId,
        public readonly ?string $tiffEpStandardString,
    ) {
    }
}
