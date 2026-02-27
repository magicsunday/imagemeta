<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

/**
 * EXIF APP2 audio format codes (EXIF 3.0 §4.7.3 / §5.4).
 */
enum JpegAudioFormat: int
{
    case Pcm      = 0x0;
    case MuLaw    = 0x1;
    case ImaAdpcm = 0x2;

    /**
     * Returns the normalized format label exposed in {@see \MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream}.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pcm      => 'PCM',
            self::MuLaw    => 'MU_LAW_PCM',
            self::ImaAdpcm => 'IMA_ADPCM',
        };
    }

    /**
     * Returns allowed sample rates for this audio format.
     *
     * @return list<int>
     */
    public function allowedSampleRates(): array
    {
        return match ($this) {
            self::Pcm      => [8_000, 11_025, 22_050, 32_000, 44_100, 48_000, 96_000, 192_000],
            self::MuLaw    => [8_000],
            self::ImaAdpcm => [8_000, 11_025, 22_050, 44_100],
        };
    }
}
