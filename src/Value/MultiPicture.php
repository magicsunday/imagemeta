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
    /** @var list<MultiPictureEntry> */
    public array $entries;

    /**
     * Creates a multi-picture format metadata value object.
     *
     * @param list<MultiPictureEntry> $entries
     */
    public function __construct(
        public ?string $version,
        public int $imageCount,
        array $entries,
        public ?int $totalFrames,
        public ?int $individualImageNumber,
        public ?string $imageUidList,
    ) {
        $this->entries = [...$entries];
    }
}
