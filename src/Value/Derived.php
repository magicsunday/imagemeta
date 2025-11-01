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
     * @param float|null $ev100                    Exposure value normalised to ISO 100.
     * @param float|null $hyperfocalDistanceMetres Hyperfocal distance in metres.
     * @param float|null $fieldOfViewDiagonalDeg   Diagonal field of view in degrees.
     * @param float|null $fieldOfViewHorizontalDeg Horizontal field of view in degrees.
     * @param float|null $fieldOfViewVerticalDeg   Vertical field of view in degrees.
     * @param int|null   $equivalent35mm           Equivalent focal length in 35mm terms.
     * @param float|null $cropFactor               Estimated crop factor.
     */
    public function __construct(
        public readonly ?float $ev100,
        public readonly ?float $hyperfocalDistanceMetres,
        public readonly ?float $fieldOfViewDiagonalDeg,
        public readonly ?float $fieldOfViewHorizontalDeg,
        public readonly ?float $fieldOfViewVerticalDeg,
        public readonly ?int $equivalent35mm,
        public readonly ?float $cropFactor,
    ) {
    }
}
