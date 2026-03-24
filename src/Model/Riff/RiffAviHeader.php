<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Parsed fields from the AVIMAINHEADER structure (avih chunk).
 *
 * Microsoft AVI RIFF File Reference — AVIMAINHEADER structure.
 */
final readonly class RiffAviHeader
{
    /**
     * @param int $microSecPerFrame Microseconds between frames.
     * @param int $width            Video width in pixels.
     * @param int $height           Video height in pixels.
     * @param int $totalFrames      Total number of video frames.
     * @param int $streams          Number of streams in the file.
     */
    public function __construct(
        public int $microSecPerFrame,
        public int $width,
        public int $height,
        public int $totalFrames,
        public int $streams,
    ) {
    }
}
