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
 * Captures audio track characteristics from container metadata.
 */
final readonly class Audio
{
    /**
     * @param int|null    $channels   Number of audio channels.
     * @param int|null    $sampleRate Sample rate in Hertz.
     * @param string|null $codec      Codec identifier used for the audio stream.
     * @param int|null    $bitDepth   Bit depth per sample.
     */
    public function __construct(
        public ?int $channels,
        public ?int $sampleRate,
        public ?string $codec,
        public ?int $bitDepth,
    ) {
    }

    /**
     * Returns the number of audio channels.
     */
    public function channels(): ?int
    {
        return $this->channels;
    }

    /**
     * Returns the sample rate in Hertz.
     */
    public function sampleRate(): ?int
    {
        return $this->sampleRate;
    }

    /**
     * Returns the codec identifier used for the audio stream.
     */
    public function codec(): ?string
    {
        return $this->codec;
    }

    /**
     * Returns the bit depth per sample.
     */
    public function bitDepth(): ?int
    {
        return $this->bitDepth;
    }
}
