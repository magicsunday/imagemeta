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
 * Describes the availability of embedded previews or thumbnails.
 */
final readonly class Preview
{
    /**
     * @param bool|null $hasThumbnail  Whether an embedded thumbnail exists.
     * @param bool|null $hasPreview    Whether an embedded preview image exists.
     * @param int|null  $previewWidth  Width of the preview image in pixels.
     * @param int|null  $previewHeight Height of the preview image in pixels.
     */
    public function __construct(
        public ?bool $hasThumbnail,
        public ?bool $hasPreview,
        public ?int $previewWidth,
        public ?int $previewHeight,
    ) {
    }
}
