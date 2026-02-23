<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Groups media content metadata: image, audio, video, thumbnail, depth map, multi-picture, and regions.
 */
final readonly class MediaContent
{
    /**
     * @param Image            $image         Image metadata.
     * @param Audio            $audio         Audio metadata.
     * @param AudioClips       $embeddedAudio Embedded audio clips.
     * @param Video            $video         Video metadata.
     * @param Thumbnail        $thumbnail     Thumbnail metadata.
     * @param DepthMap         $depthMap      Depth map metadata.
     * @param MultiPicture     $multiPicture  Multi-picture metadata.
     * @param RegionCollection $regions       Detected regions (face, object).
     */
    public function __construct(
        public Image $image,
        public Audio $audio,
        public AudioClips $embeddedAudio,
        public Video $video,
        public Thumbnail $thumbnail,
        public DepthMap $depthMap,
        public MultiPicture $multiPicture,
        public RegionCollection $regions,
    ) {
    }
}
