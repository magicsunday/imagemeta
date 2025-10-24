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
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Depth;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\RawCharacteristics;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Uav;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Aggregates curated metadata in a structured representation with typed sub objects.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public Interop $interop,
        public TiffData $tiff,
        public Depth $depth,
        public CompositeImageInfo $composite,
        public Standards $standards,
        public Camera $camera,
        public Lens $lens,
        public Image $image,
        public Exposure $exposure,
        public Capture $capture,
        public Gps $gps,
        public Device $device,
        public Apple $apple,
        public Xmp $xmp,
        public File $file,
        public Container $container,
        public Preview $preview,
        public Video $video,
        public Audio $audio,
        public ColorProfile $colorProfile,
        public ProcessingSettings $processing,
        public WhiteBalanceDetails $whiteBalanceDetails,
        public Focus $focus,
        public Motion $motion,
        public Scene $scene,
        public Regions $regions,
        public Keywords $keywords,
        public Rights $rights,
        public Author $author,
        public Temporal $temporal,
        public Derived $derived,
        public RelatedAssets $related,
        public RawCharacteristics $raw,
        public Sensor $sensor,
        public Uav $uav,
        public Integrity $integrity,
    ) {
    }
}
