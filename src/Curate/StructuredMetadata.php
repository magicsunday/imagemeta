<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Iptc;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Aggregates curated metadata as immutable value objects that can be accessed fluently.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public Audio $audio,
        public AudioClips $embeddedAudio,
        public Author $author,
        public Camera $camera,
        public Capture $capture,
        public ColorProfile $colorProfile,
        public CompositeImageInfo $composite,
        public Container $container,
        public Derived $derived,
        public DepthMap $depthMap,
        public Device $device,
        public Exposure $exposure,
        public File $file,
        public FlashPix $flashPix,
        public Focus $focus,
        public Gps $gps,
        public Image $image,
        public Integrity $integrity,
        public Interop $interop,
        public Iptc $iptc,
        public Keywords $keywords,
        public Lens $lens,
        public Motion $motion,
        public MultiPicture $multiPicture,
        public ProcessingSettings $processing,
        public Regions $regions,
        public RelatedAssets $related,
        public Rights $rights,
        public Scene $scene,
        public Sensor $sensor,
        public Standards $standards,
        public Temporal $temporal,
        public Thumbnail $thumbnail,
        public TiffData $tiff,
        public Video $video,
        public WhiteBalanceDetails $whiteBalance,
        public Xmp $xmp,
        public ?AppleMakerNotes $makerNotesApple,
    ) {
    }
}
