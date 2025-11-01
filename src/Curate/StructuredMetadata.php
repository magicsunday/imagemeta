<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;

/**
 * Aggregates curated EXIF-centric metadata as immutable value objects.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public ?ParsedExif $exif,
        public File $file,
        public Container $container,
        public Integrity $integrity,
        public Image $image,
        public Exposure $exposure,
        public Gps $gps,
    ) {
    }
}
