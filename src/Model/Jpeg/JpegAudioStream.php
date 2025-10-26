<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Jpeg;

/**
 * Represents an embedded EXIF audio stream carried within JPEG APP2 segments.
 */
final readonly class JpegAudioStream
{
    /**
     * @param string $format     Audio encoding identifier (e.g. PCM, MU_LAW_PCM, IMA_ADPCM).
     * @param int    $channels   Channel count (1 for mono, 2 for stereo).
     * @param int    $sampleRate Sample rate in Hertz.
     * @param int    $bitDepth   Bit depth per sample.
     * @param string $data       Raw audio payload extracted from the segment.
     * @param string $version    Version string reported by the segment header.
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
