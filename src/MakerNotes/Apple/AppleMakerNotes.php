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
 * Represents curated maker note data extracted from Apple devices.
 */
final readonly class AppleMakerNotes
{
    /**
     * @param string|null         $contentIdentifier   Unique content identifier assigned by Apple platforms.
     * @param string|null         $cameraType          Describes the hardware camera (e.g. "Wide", "Tele").
     * @param float|null          $hdrHeadroom         HDR headroom value reported by the device.
     * @param list<float>|null    $hdrGain             HDR gain values per colour channel.
     * @param float|null          $snr                 Signal-to-noise ratio setting.
     * @param float|null          $focusPosition       Lens focus position in the native scale.
     * @param int|null            $livePhotoIndex      Index of the representative frame in a Live Photo sequence.
     * @param int|null            $colorTemperature    White balance colour temperature in Kelvin.
     * @param string|null         $semanticStylePreset Selected semantic style preset name.
     * @param float|null          $semanticStyleWarmth Semantic style warmth adjustment.
     * @param float|null          $semanticStyleTone   Semantic style tone adjustment.
     * @param array<string, bool> $flags               Boolean flags derived from maker note keys.
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
