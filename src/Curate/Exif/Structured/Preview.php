<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use MagicSunday\ImageMeta\Value\Preview as PreviewValue;

/**
 * Indicates the availability of previews and thumbnails from EXIF.
 */
final readonly class Preview
{
    public ?bool $hasThumbnail;

    public ?bool $hasPreview;

    public ?int $previewWidth;

    public ?int $previewHeight;

    public function __construct(PreviewValue $preview)
    {
        $this->hasThumbnail  = $preview->hasThumbnail;
        $this->hasPreview    = $preview->hasPreview;
        $this->previewWidth  = $preview->previewWidth;
        $this->previewHeight = $preview->previewHeight;
    }
}
