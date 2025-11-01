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
     * @param float|null  $durationSec      Duration in seconds.
     * @param float|null  $frameRate        Frame rate in frames per second.
     * @param int|null    $width            Encoded width in pixels.
     * @param int|null    $height           Encoded height in pixels.
     * @param string|null $codec            Codec identifier used for the video track.
     * @param bool|null   $hdr              Indicates HDR mastering.
     * @param string|null $transferFunction Transfer function identifier (PQ/HLG/...).
     * @param string|null $colorPrimaries   Colour primaries name as reported by the container.
     */
    public function __construct(
        public readonly ?float $durationSec,
        public readonly ?float $frameRate,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $codec,
        public readonly ?bool $hdr,
        public readonly ?string $transferFunction,
        public readonly ?string $colorPrimaries,
    ) {
    }
}
