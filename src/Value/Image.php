<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;

/**
 * Encapsulates image level metadata.
 */
final readonly class Image
{
    /**
     * @param Orientation|null $orientation Image orientation when stored on disk.
     * @param ColorSpace|null  $colorSpace  Colour space used for the pixel data.
     */
    public function __construct(
        public ?Orientation $orientation,
        public ?ColorSpace $colorSpace,
    ) {
    }
}
