<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use function array_find;
use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function strtolower;
use function trim;

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
     * Mapping of shorthand lookup keys to canonical QuickTime metadata identifiers.
     *
     * @var array<string, list<string>>
     */
    private const array KEY_ALIASES = [
        self::MAJOR_BRAND_KEY           => [self::MAJOR_BRAND_KEY, 'MajorBrand'],
        'MajorBrand'                    => ['MajorBrand', self::MAJOR_BRAND_KEY],
        self::MINOR_VERSION_KEY         => [self::MINOR_VERSION_KEY, 'MinorVersion'],
        'MinorVersion'                  => ['MinorVersion', self::MINOR_VERSION_KEY],
        self::COMPATIBLE_BRANDS_KEY     => [self::COMPATIBLE_BRANDS_KEY, 'CompatibleBrands'],
        'CompatibleBrands'              => ['CompatibleBrands', self::COMPATIBLE_BRANDS_KEY],
        self::HANDLER_DESCRIPTION_KEY   => [self::HANDLER_DESCRIPTION_KEY, 'HandlerDescription'],
        'HandlerDescription'            => ['HandlerDescription', self::HANDLER_DESCRIPTION_KEY],
        self::VIDEO_WIDTH_KEY           => [self::VIDEO_WIDTH_KEY, 'ImageWidth', 'VideoWidth'],
        'ImageWidth'                    => ['ImageWidth', self::VIDEO_WIDTH_KEY, 'VideoWidth'],
        self::VIDEO_HEIGHT_KEY          => [self::VIDEO_HEIGHT_KEY, 'ImageHeight', 'VideoHeight'],
        'ImageHeight'                   => ['ImageHeight', self::VIDEO_HEIGHT_KEY, 'VideoHeight'],
        self::VIDEO_CODEC_KEY           => [self::VIDEO_CODEC_KEY, 'CompressorID', 'VideoCodecID'],
        'CompressorID'                  => ['CompressorID', self::VIDEO_CODEC_KEY, 'VideoCodecID'],
        self::COMPRESSOR_NAME_KEY       => [self::COMPRESSOR_NAME_KEY, 'CompressorName'],
        'CompressorName'                => ['CompressorName', self::COMPRESSOR_NAME_KEY],
        self::AUDIO_FORMAT_KEY          => [self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioFormat', 'AudioCodecID'],
        self::AUDIO_CODEC_KEY           => [self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioCodecID', 'AudioFormat'],
        'AudioFormat'                   => ['AudioFormat', self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioCodecID'],
        'AudioCodecID'                  => ['AudioCodecID', self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioFormat'],
        self::AUDIO_CHANNELS_KEY        => [self::AUDIO_CHANNELS_KEY, 'AudioChannels'],
        'AudioChannels'                 => ['AudioChannels', self::AUDIO_CHANNELS_KEY],
        self::AUDIO_SAMPLE_RATE_KEY     => [self::AUDIO_SAMPLE_RATE_KEY, 'AudioSampleRate'],
        'AudioSampleRate'               => ['AudioSampleRate', self::AUDIO_SAMPLE_RATE_KEY],
        self::AUDIO_BITS_PER_SAMPLE_KEY => [self::AUDIO_BITS_PER_SAMPLE_KEY, 'AudioBitsPerSample'],
        'AudioBitsPerSample'            => ['AudioBitsPerSample', self::AUDIO_BITS_PER_SAMPLE_KEY],
        'Encoder'                       => ['Encoder', 'com.apple.quicktime.encoder'],
        'AvgBitrate'                    => ['AvgBitrate', 'com.apple.quicktime.avgBitrate'],
        'Bitrate'                       => ['Bitrate', 'com.apple.quicktime.bitrate', 'com.apple.quicktime.dataRate'],
        'Duration'                      => ['Duration', 'com.apple.quicktime.duration'],
        'VideoFrameRate'                => ['VideoFrameRate', 'com.apple.quicktime.videoFrameRate'],
        'HDRFormat'                     => ['HDRFormat', 'com.apple.quicktime.hdrFormat'],
        'TransferFunction'              => ['TransferFunction', 'com.apple.quicktime.transferFunction'],
        'ColorPrimaries'                => ['ColorPrimaries', 'com.apple.quicktime.colorPrimaries'],
        'AudioBitsPerChannel'           => ['AudioBitsPerChannel', self::AUDIO_BITS_PER_SAMPLE_KEY],
    ];

    /**
     * Creates a new instance of QuickTime metadata information.
     *
     * @param array<string, string|int|float|bool> $keys Map of QuickTime metadata keys and their values.
     */
    public function __construct(public array $keys)
    {
    }

    /**
     * Returns a string value for the given metadata key when present.
     */
    public function stringValue(string $key): ?string
    {
        $value = $this->lookupValue($key);

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Returns an integer value for the given metadata key when present.
     */
    public function intValue(string $key): ?int
    {
        $value = $this->lookupValue($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Returns a float value for the given metadata key when present.
     */
    public function floatValue(string $key): ?float
    {
        $value = $this->lookupValue($key);

        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns a boolean value for the given metadata key when present.
     */
    public function boolValue(string $key): ?bool
    {
        $value = $this->lookupValue($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return null;
    }

    /**
     * Resolves the first available value for the given metadata key or its aliases.
     *
     * @return string|int|float|bool|null
     */
    private function lookupValue(string $key): string|int|float|bool|null
    {
        $candidates = self::KEY_ALIASES[$key] ?? [$key];

        $candidate = array_find(
            $candidates,
            fn (string $candidate): bool => array_key_exists($candidate, $this->keys),
        );

        return $candidate === null ? null : $this->keys[$candidate];
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
