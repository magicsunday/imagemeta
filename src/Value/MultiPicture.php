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
 * Aggregates MPF information for clients interested in additional image frames.
 */
final readonly class MultiPicture
{
    /**
     * @param list<MultiPictureEntry>                          $entries
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAngle
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAxis
     */
    public function __construct(
        public readonly ?string $version,
        public readonly int $imageCount,
        public readonly array $entries,
        public readonly ?int $totalFrames,
        public readonly ?int $individualImageNumber,
        public readonly ?string $imageUidList,
        public readonly ?array $panoramaAngle,
        public readonly ?array $panoramaAxis,
    ) {
    }
}
