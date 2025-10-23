<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Regions;

/**
 * Represents a rectangular region annotation as defined by XMP MWG-RS metadata.
 */
final readonly class Region
{
    /**
     * @param string      $type       Region type identifier (e.g. face, rect).
     * @param float       $x          Normalised X coordinate of the top left corner.
     * @param float       $y          Normalised Y coordinate of the top left corner.
     * @param float       $w          Normalised width of the region.
     * @param float       $h          Normalised height of the region.
     * @param string|null $personName Associated person name when the region marks a face.
     * @param float|null  $confidence Detection confidence value if provided.
     */
    public function __construct(
        public string $type,
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        public ?string $personName,
        public ?float $confidence,
    ) {
    }
}
