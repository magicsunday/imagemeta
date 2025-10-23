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
 * Holds derived secondary metrics calculated from the base metadata.
 */
final readonly class Derived
{
    /**
     * @param float|null $ev100            Exposure value normalised to ISO 100.
     * @param float|null $hyperfocalM      Hyperfocal distance in metres.
     * @param float|null $fovDeg           Approximate diagonal field of view in degrees.
     * @param int|null   $focalLength35mm  Equivalent focal length in 35mm terms.
     * @param float|null $cropFactor       Estimated crop factor.
     */
    public function __construct(
        public ?float $ev100,
        public ?float $hyperfocalM,
        public ?float $fovDeg,
        public ?int $focalLength35mm,
        public ?float $cropFactor,
    ) {
    }
}
