<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\Video;

/**
 * Media related metadata such as image dimensions and embedded streams.
 */
final readonly class MediaMetadata
{
    public function __construct(
        public Image $image,
        public Preview $preview,
        public Video $video,
        public Audio $audio,
        public AudioClips $embeddedAudio,
        public ColorProfile $colorProfile,
        public CompositeImageInfo $composite,
        public MultiPicture $multiPicture,
    ) {
    }
}
