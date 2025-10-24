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
 * Holds Apple specific metadata aggregated from maker notes and QuickTime sources.
 */
final readonly class Apple
{
    /**
     * @param string|null         $contentIdentifier   Unique content identifier assigned by Apple platforms.
     * @param string|null         $cameraType          Reported hardware camera type (e.g. "Wide", "Tele").
     * @param float|null          $hdrHeadroom         HDR headroom value reported by the capture pipeline.
     * @param list<float>|null    $hdrGain             HDR gain values per colour channel.
     * @param float|null          $snr                 Signal-to-noise ratio setting applied during capture.
     * @param float|null          $focusPosition       Focus position within the device specific range.
     * @param int|null            $livePhotoIndex      Index of the still frame for Live Photo sequences.
     * @param int|null            $colorTemperature    White balance colour temperature in Kelvin.
     * @param string|null         $semanticStylePreset Semantic style preset name.
     * @param float|null          $semanticStyleWarmth Semantic style warmth value.
     * @param float|null          $semanticStyleTone   Semantic style tone value.
     * @param array<string, bool> $flags               Boolean flags derived from maker notes or QuickTime metadata.
     * @param list<float>|null    $accelerationVector  Acceleration vector recorded during capture.
     */
    public function __construct(
        public ?string $contentIdentifier,
        public ?string $cameraType,
        public ?float $hdrHeadroom,
        public ?array $hdrGain,
        public ?float $snr,
        public ?float $focusPosition,
        public ?int $livePhotoIndex,
        public ?int $colorTemperature,
        public ?string $semanticStylePreset,
        public ?float $semanticStyleWarmth,
        public ?float $semanticStyleTone,
        public array $flags,
        public ?array $accelerationVector,
    ) {
    }
}
