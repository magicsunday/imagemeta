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
        private File $file,
        private Container $container,
        private Integrity $integrity,
        public Camera $camera,
        private Device $device,
        public Lens $lens,
        public Derived $derived,
        public Image $image,
        public Preview $preview,
        private Video $video,
        private Audio $audio,
        private AudioClips $embeddedAudio,
        private ColorProfile $colorProfile,
        private CompositeImageInfo $composite,
        private MultiPicture $multiPicture,
        public Exposure $exposure,
        private Capture $capture,
        private Scene $scene,
        private Temporal $temporal,
        private Regions $regions,
        private Keywords $keywords,
        public Gps $gps,
        private Sensor $sensor,
        private Focus $focus,
        private Motion $motion,
        private Uav $uav,
        private ProcessingSettings $processing,
        private WhiteBalanceDetails $whiteBalance,
        public Interop $interop,
        private TiffData $tiff,
        public Standards $standards,
        private FlashPix $flashPix,
        private Xmp $xmp,
        private Rights $rights,
        private Author $author,
        private RelatedAssets $related,
        private ?AppleMakerNotes $makerNotesApple,
    ) {
    }

    public function file(): File
    {
        return $this->file;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function integrity(): Integrity
    {
        return $this->integrity;
    }

    public function device(): Device
    {
        return $this->device;
    }

    public function video(): Video
    {
        return $this->video;
    }

    public function audio(): Audio
    {
        return $this->audio;
    }

    public function embeddedAudio(): AudioClips
    {
        return $this->embeddedAudio;
    }

    public function colorProfile(): ColorProfile
    {
        return $this->colorProfile;
    }

    public function composite(): CompositeImageInfo
    {
        return $this->composite;
    }

    public function multiPicture(): MultiPicture
    {
        return $this->multiPicture;
    }

    public function capture(): Capture
    {
        return $this->capture;
    }

    public function scene(): Scene
    {
        return $this->scene;
    }

    public function temporal(): Temporal
    {
        return $this->temporal;
    }

    public function regions(): Regions
    {
        return $this->regions;
    }

    public function keywords(): Keywords
    {
        return $this->keywords;
    }

    public function sensor(): Sensor
    {
        return $this->sensor;
    }

    public function focus(): Focus
    {
        return $this->focus;
    }

    public function motion(): Motion
    {
        return $this->motion;
    }

    public function uav(): Uav
    {
        return $this->uav;
    }

    public function processing(): ProcessingSettings
    {
        return $this->processing;
    }

    public function whiteBalance(): WhiteBalanceDetails
    {
        return $this->whiteBalance;
    }

    public function tiff(): TiffData
    {
        return $this->tiff;
    }

    public function flashPix(): FlashPix
    {
        return $this->flashPix;
    }

    public function xmp(): Xmp
    {
        return $this->xmp;
    }

    public function rights(): Rights
    {
        return $this->rights;
    }

    public function author(): Author
    {
        return $this->author;
    }

    public function related(): RelatedAssets
    {
        return $this->related;
    }

    public function makerNotesApple(): ?AppleMakerNotes
    {
        return $this->makerNotesApple;
    }
}
