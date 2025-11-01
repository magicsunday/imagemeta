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
 * Represents a single embedded audio clip extracted from a JPEG container.
 */
final readonly class AudioClip
{
    /**
     * @param string $format     Audio encoding identifier (e.g. PCM, MU_LAW_PCM, IMA_ADPCM).
     * @param int    $channels   Channel count for the clip.
     * @param int    $sampleRate Sample rate in Hertz.
     * @param int    $bitDepth   Bit depth per sample.
     * @param string $data       Raw audio payload.
     * @param string $version    Version string reported by the EXIF audio header.
     */
    public function __construct(
        public string $format,
        public int $channels,
        public int $sampleRate,
        public int $bitDepth,
        public string $data,
        public string $version,
    ) {
    }
}
