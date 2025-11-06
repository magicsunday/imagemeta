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
 * Represents video specific metadata derived from QuickTime/ISOBMFF sources.
 */
final readonly class Video
{
    /**
     * Creates a video metadata value object.
     *
     * @param float|null  $durationSec      Duration in seconds.
     * @param float|null  $frameRate        Frame rate in frames per second.
     * @param int|null    $width            Encoded width in pixels.
     * @param int|null    $height           Encoded height in pixels.
     * @param string|null $codec            Codec identifier used for the video track.
     * @param bool        $hdr              Indicates HDR mastering.
     * @param string|null $transferFunction Transfer function identifier (PQ/HLG/...).
     * @param string|null $colorPrimaries   Colour primaries name as reported by the container.
     */
    public function __construct(
        public ?float $durationSec = null,
        public ?float $frameRate = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $codec = null,
        public bool $hdr = false,
        public ?string $transferFunction = null,
        public ?string $colorPrimaries = null,
    ) {
    }
}
