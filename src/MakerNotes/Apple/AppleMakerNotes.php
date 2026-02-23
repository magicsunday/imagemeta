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
    public static function empty(): self
    {
        /** @var self|null $empty */
        static $empty = null;

        if ($empty === null) {
            $empty = new self(
                identity: null,
                hdr: null,
                autoExposure: null,
                autoFocus: null,
                noise: null,
                semanticStyle: null,
                livePhoto: null,
                camera: null,
                flags: [],
            );
        }

        return $empty;
    }

    /**
     * @param AppleCaptureIdentity|null $identity      Unique identifiers for the captured image.
     * @param AppleHdr|null             $hdr           HDR capture metadata.
     * @param AppleAutoExposure|null    $autoExposure  Auto exposure parameters.
     * @param AppleAutoFocus|null       $autoFocus     Auto focus parameters.
     * @param AppleNoise|null           $noise         Signal-to-noise and luminance noise data.
     * @param AppleSemanticStyle|null   $semanticStyle Semantic style adjustments.
     * @param AppleLivePhoto|null       $livePhoto     Live Photo and motion metadata.
     * @param AppleCameraCapture|null   $camera        Camera hardware and capture settings.
     * @param array<string, bool>       $flags         Boolean flags derived from maker note keys.
     */
    public function __construct(
        public ?AppleCaptureIdentity $identity,
        public ?AppleHdr $hdr,
        public ?AppleAutoExposure $autoExposure,
        public ?AppleAutoFocus $autoFocus,
        public ?AppleNoise $noise,
        public ?AppleSemanticStyle $semanticStyle,
        public ?AppleLivePhoto $livePhoto,
        public ?AppleCameraCapture $camera,
        public array $flags,
    ) {
    }
}
