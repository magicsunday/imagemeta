<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

/**
 * Holds QuickTime metadata keys that are extracted from QuickTime containers.
 */
final readonly class QuickTimeMeta
{
    /**
     * QuickTime metadata key used for the content identifier value.
     */
    public const string CONTENT_IDENTIFIER_KEY = 'com.apple.quicktime.content.identifier';

    /**
     * QuickTime metadata key representing the declared container major brand.
     */
    public const string MAJOR_BRAND_KEY = 'com.apple.quicktime.majorBrand';

    /**
     * QuickTime metadata key exposing the minor version of the container brand.
     */
    public const string MINOR_VERSION_KEY = 'com.apple.quicktime.minorVersion';

    /**
     * QuickTime metadata key listing compatible brands.
     */
    public const string COMPATIBLE_BRANDS_KEY = 'com.apple.quicktime.compatibleBrands';

    /**
     * QuickTime metadata key describing the handler for a media track.
     */
    public const string HANDLER_DESCRIPTION_KEY = 'com.apple.quicktime.handlerDescription';

    /**
     * QuickTime metadata key exposing the display width of the primary video track.
     */
    public const string VIDEO_WIDTH_KEY = 'com.apple.quicktime.videoWidth';

    /**
     * QuickTime metadata key exposing the display height of the primary video track.
     */
    public const string VIDEO_HEIGHT_KEY = 'com.apple.quicktime.videoHeight';

    /**
     * QuickTime metadata key describing the codec four-character code for video.
     */
    public const string VIDEO_CODEC_KEY = 'com.apple.quicktime.videoCodec';

    /**
     * QuickTime metadata key exposing the human readable compressor name.
     */
    public const string COMPRESSOR_NAME_KEY = 'com.apple.quicktime.compressorName';

    /**
     * QuickTime metadata key describing the audio format four-character code.
     */
    public const string AUDIO_FORMAT_KEY = 'com.apple.quicktime.audioFormat';

    /**
     * QuickTime metadata key describing the audio codec identifier.
     */
    public const string AUDIO_CODEC_KEY = 'com.apple.quicktime.audioCodec';

    /**
     * QuickTime metadata key exposing the audio channel count.
     */
    public const string AUDIO_CHANNELS_KEY = 'com.apple.quicktime.audioChannels';

    /**
     * QuickTime metadata key exposing the audio sample rate in Hz.
     */
    public const string AUDIO_SAMPLE_RATE_KEY = 'com.apple.quicktime.audioSampleRate';

    /**
     * QuickTime metadata key exposing the audio bit depth per sample.
     */
    public const string AUDIO_BITS_PER_SAMPLE_KEY = 'com.apple.quicktime.audioBitsPerSample';

    /**
     * Creates a new instance of QuickTime metadata information.
     *
     * @param array<string, string|int|float|bool> $keys Map of QuickTime metadata keys and their values.
     */
    public function __construct(public array $keys)
    {
    }

    /**
     * Returns the QuickTime content identifier value when available.
     *
     * @return string|null
     */
    public function contentIdentifier(): ?string
    {
        $key = self::CONTENT_IDENTIFIER_KEY;

        return isset($this->keys[$key]) ? (string) $this->keys[$key] : null;
    }
}
