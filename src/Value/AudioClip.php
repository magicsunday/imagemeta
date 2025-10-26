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
 * Represents a decoded EXIF audio stream extracted from JPEG APP2 segments.
 */
final readonly class AudioClip
{
    /**
     * @param string $data       Raw audio payload (typically PCM or container-specific data).
     * @param int    $channels   Number of audio channels.
     * @param int    $sampleRate Sampling rate in Hertz.
     * @param string $codec      Codec identifier declared by the EXIF audio header.
     * @param int    $bitDepth   Bits per audio sample when applicable.
     */
    public function __construct(
        public string $data,
        public int $channels,
        public int $sampleRate,
        public string $codec,
        public int $bitDepth,
    ) {
    }
}
