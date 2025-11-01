<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;

/**
 * Aggregates audio clips extracted from EXIF audio APP2 segments.
 */
final readonly class AudioClips
{
    /**
     * @param list<AudioClip> $clips
     */
    public function __construct(public array $clips)
    {
    }

    /**
     * Builds an audio clip aggregate from JPEG audio stream descriptors.
     *
     * @param list<JpegAudioStream> $streams
     */
    public static function fromJpegAudioStreams(array $streams): self
    {
        $clips = [];
        foreach ($streams as $stream) {
            $clips[] = new AudioClip(
                $stream->format,
                $stream->channels,
                $stream->sampleRate,
                $stream->bitDepth,
                $stream->data,
                $stream->version,
            );
        }

        return new self($clips);
    }
}
