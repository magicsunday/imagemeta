<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\CompositeImage;

/**
 * Describes composite image creation metadata such as HDR or multi-frame stacks.
 */
final readonly class CompositeImageInfo
{
    /**
     * Creates a composite image information metadata value object.
     *
     * @param CompositeImage|null     $type               Composite image classification.
     * @param array{0:int,1:int}|null $counts             Pair of [sourceCount, usedCount].
     * @param SourceExposureTimes|null $sourceExposureTimes Exposure timing statistics for the contributing frames.
     */
    public function __construct(
        public ?CompositeImage $type,
        public ?array $counts,
        public ?SourceExposureTimes $sourceExposureTimes,
    ) {
    }
}
