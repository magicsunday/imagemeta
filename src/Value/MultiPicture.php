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
     * Creates a multi-picture format metadata value object.
     *
     * @param string|null                                      $version
     * @param int                                              $imageCount
     * @param list<MultiPictureEntry>                          $entries
     * @param int|null                                         $totalFrames
     * @param int|null                                         $individualImageNumber
     * @param string|null                                      $imageUidList
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAngle
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAxis
     */
    public function __construct(
        public ?string $version,
        public int $imageCount,
        public array $entries,
        public ?int $totalFrames,
        public ?int $individualImageNumber,
        public ?string $imageUidList,
        public ?array $panoramaAngle,
        public ?array $panoramaAxis,
    ) {
    }
}
