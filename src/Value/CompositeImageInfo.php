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
     * @param CompositeImage|null     $type               Composite image classification.
     * @param array{0:int,1:int}|null $counts             Pair of [sourceCount, usedCount].
     * @param list<float>|null        $exposureTimesTotal Exposure times for contributing frames.
     */
    public function __construct(
        public ?CompositeImage $type,
        public ?array $counts,
        public ?array $exposureTimesTotal,
    ) {
    }

    /**
     * Returns the composite image classification.
     */
    public function type(): ?CompositeImage
    {
        return $this->type;
    }

    /**
     * Returns the pair of source and used frame counts.
     *
     * @return array{0:int,1:int}|null
     */
    public function counts(): ?array
    {
        return $this->counts;
    }

    /**
     * Returns the exposure times for contributing frames.
     *
     * @return list<float>|null
     */
    public function exposureTimesTotal(): ?array
    {
        return $this->exposureTimesTotal;
    }
}
