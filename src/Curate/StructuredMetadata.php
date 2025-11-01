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
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
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
 * Aggregates curated metadata as immutable value objects that can be accessed fluently.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public File $file,
        public Container $container,
        public Integrity $integrity,
        public Camera $camera,
        public Device $device,
        public Lens $lens,
        public Derived $derived,
        public Image $image,
        public Preview $preview,
        public Video $video,
        public Audio $audio,
        public AudioClips $embeddedAudio,
        public ColorProfile $colorProfile,
        public CompositeImageInfo $composite,
        public MultiPicture $multiPicture,
        public Exposure $exposure,
        public Capture $capture,
        public Scene $scene,
        public Temporal $temporal,
        public Regions $regions,
        public Keywords $keywords,
        public Gps $gps,
        public Sensor $sensor,
        public Focus $focus,
        public Motion $motion,
        public Uav $uav,
        public ProcessingSettings $processing,
        public WhiteBalanceDetails $whiteBalance,
        public Interop $interop,
        public TiffData $tiff,
        public Standards $standards,
        public FlashPix $flashPix,
        public Xmp $xmp,
        public Rights $rights,
        public Author $author,
        public RelatedAssets $related,
        public ?AppleMakerNotes $makerNotesApple,
    ) {
    }
}
