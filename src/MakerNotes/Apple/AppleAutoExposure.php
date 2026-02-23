<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Auto exposure parameters extracted from Apple maker notes.
 */
final readonly class AppleAutoExposure
{
    /**
     * @param bool|null  $stable  Indicates whether auto exposure was stable during capture.
     * @param float|null $target  Auto exposure target luminance value.
     * @param float|null $average Auto exposure average luminance value.
     */
    public function __construct(
        public ?bool $stable,
        public ?float $target,
        public ?float $average,
    ) {
    }
}
