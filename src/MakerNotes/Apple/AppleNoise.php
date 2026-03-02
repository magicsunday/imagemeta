<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Signal-to-noise and luminance noise data extracted from Apple maker notes.
 */
final readonly class AppleNoise
{
    /**
     * @param float|null      $snr                    Signal-to-noise ratio setting.
     * @param string|int|null $signalToNoiseRatioType Signal-to-noise ratio measurement type identifier.
     * @param float|null      $luminanceAmplitude     Luminance noise amplitude measured for the capture.
     */
    public function __construct(
        public ?float $snr,
        public string|int|null $signalToNoiseRatioType,
        public ?float $luminanceAmplitude,
    ) {
    }
}
