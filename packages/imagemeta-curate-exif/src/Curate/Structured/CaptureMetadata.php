<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Temporal;

/**
 * Capture context including scene classification and temporal metadata.
 */
final readonly class CaptureMetadata
{
    public function __construct(
        public Capture $details,
        public Scene $scene,
        public Temporal $temporal,
        public Regions $regions,
        public Keywords $keywords,
    ) {
    }
}
