<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Value\Apple;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Aggregates curated metadata in a structured representation with typed sub objects.
 */
final readonly class StructuredMetadata
{
    /**
     * @param Camera|null  $camera  Camera specific information.
     * @param Lens|null    $lens    Lens information.
     * @param Image|null   $image   Image level metadata.
     * @param Exposure|null $exposure Exposure parameters used during capture.
     * @param Capture|null $capture Capture timestamps.
     * @param Gps|null     $gps     GPS coordinates.
     * @param Device|null  $device  Device metadata from container sources.
     * @param Apple|null   $apple   Apple specific metadata.
     * @param Xmp|null     $xmp     Parsed XMP access wrapper.
     */
    public function __construct(
        public ?Camera $camera,
        public ?Lens $lens,
        public ?Image $image,
        public ?Exposure $exposure,
        public ?Capture $capture,
        public ?Gps $gps,
        public ?Device $device,
        public ?Apple $apple,
        public ?Xmp $xmp,
    ) {
    }
}
