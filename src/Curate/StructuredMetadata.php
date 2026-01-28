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
use MagicSunday\ImageMeta\Value\{
    Audio,
    AudioClips,
    Author,
    Camera,
    Capture,
    ColorProfile,
    CompositeImageInfo,
    Container,
    DepthMap,
    Derived,
    Device,
    Exposure,
    File,
    FlashPix,
    Focus,
    Gps,
    Image,
    Integrity,
    Interop,
    Iptc,
    Keywords,
    Lens,
    Motion,
    MultiPicture,
    ProcessingSettings,
    Regions,
    RelatedAssets,
    Rights,
    Scene,
    Sensor,
    Standards,
    Temporal,
    Thumbnail,
    TiffData,
    Video,
    WhiteBalanceDetails,
    Xmp,
};

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
