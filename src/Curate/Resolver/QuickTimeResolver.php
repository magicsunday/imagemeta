<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function strtolower;
use function trim;

/**
 * Facilitates access to QuickTime metadata keys.
 */
final readonly class QuickTimeResolver
{
    /**
     * Mapping of shorthand lookup keys to canonical QuickTime metadata identifiers.
     *
     * @var array<string, list<string>>
     */
    private const array KEY_ALIASES = [
        QuickTimeMeta::MAJOR_BRAND_KEY          => [QuickTimeMeta::MAJOR_BRAND_KEY, 'MajorBrand'],
        'MajorBrand'                            => ['MajorBrand', QuickTimeMeta::MAJOR_BRAND_KEY],
        QuickTimeMeta::MINOR_VERSION_KEY        => [QuickTimeMeta::MINOR_VERSION_KEY, 'MinorVersion'],
        'MinorVersion'                          => ['MinorVersion', QuickTimeMeta::MINOR_VERSION_KEY],
        QuickTimeMeta::COMPATIBLE_BRANDS_KEY    => [QuickTimeMeta::COMPATIBLE_BRANDS_KEY, 'CompatibleBrands'],
        'CompatibleBrands'                      => ['CompatibleBrands', QuickTimeMeta::COMPATIBLE_BRANDS_KEY],
        QuickTimeMeta::HANDLER_DESCRIPTION_KEY  => [QuickTimeMeta::HANDLER_DESCRIPTION_KEY, 'HandlerDescription'],
        'HandlerDescription'                    => ['HandlerDescription', QuickTimeMeta::HANDLER_DESCRIPTION_KEY],
        QuickTimeMeta::VIDEO_WIDTH_KEY          => [QuickTimeMeta::VIDEO_WIDTH_KEY, 'ImageWidth', 'VideoWidth'],
        'ImageWidth'                            => ['ImageWidth', QuickTimeMeta::VIDEO_WIDTH_KEY, 'VideoWidth'],
        QuickTimeMeta::VIDEO_HEIGHT_KEY         => [QuickTimeMeta::VIDEO_HEIGHT_KEY, 'ImageHeight', 'VideoHeight'],
        'ImageHeight'                           => ['ImageHeight', QuickTimeMeta::VIDEO_HEIGHT_KEY, 'VideoHeight'],
        QuickTimeMeta::VIDEO_CODEC_KEY          => [QuickTimeMeta::VIDEO_CODEC_KEY, 'CompressorID', 'VideoCodecID'],
        'CompressorID'                          => ['CompressorID', QuickTimeMeta::VIDEO_CODEC_KEY, 'VideoCodecID'],
        QuickTimeMeta::COMPRESSOR_NAME_KEY      => [QuickTimeMeta::COMPRESSOR_NAME_KEY, 'CompressorName'],
        'CompressorName'                        => ['CompressorName', QuickTimeMeta::COMPRESSOR_NAME_KEY],
        QuickTimeMeta::AUDIO_FORMAT_KEY         => [QuickTimeMeta::AUDIO_FORMAT_KEY, QuickTimeMeta::AUDIO_CODEC_KEY, 'AudioFormat', 'AudioCodecID'],
        QuickTimeMeta::AUDIO_CODEC_KEY          => [QuickTimeMeta::AUDIO_CODEC_KEY, QuickTimeMeta::AUDIO_FORMAT_KEY, 'AudioCodecID', 'AudioFormat'],
        'AudioFormat'                           => ['AudioFormat', QuickTimeMeta::AUDIO_FORMAT_KEY, QuickTimeMeta::AUDIO_CODEC_KEY, 'AudioCodecID'],
        'AudioCodecID'                          => ['AudioCodecID', QuickTimeMeta::AUDIO_CODEC_KEY, QuickTimeMeta::AUDIO_FORMAT_KEY, 'AudioFormat'],
        QuickTimeMeta::AUDIO_CHANNELS_KEY       => [QuickTimeMeta::AUDIO_CHANNELS_KEY, 'AudioChannels'],
        'AudioChannels'                         => ['AudioChannels', QuickTimeMeta::AUDIO_CHANNELS_KEY],
        QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY    => [QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY, 'AudioSampleRate'],
        'AudioSampleRate'                       => ['AudioSampleRate', QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY],
        QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY => [QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY, 'AudioBitsPerSample'],
        'AudioBitsPerSample'                    => ['AudioBitsPerSample', QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY],
        'Encoder'                               => ['Encoder', 'com.apple.quicktime.encoder'],
        'AvgBitrate'                            => ['AvgBitrate', 'com.apple.quicktime.avgBitrate'],
        'Bitrate'                               => ['Bitrate', 'com.apple.quicktime.bitrate', 'com.apple.quicktime.dataRate'],
        'Duration'                              => ['Duration', 'com.apple.quicktime.duration'],
        'VideoFrameRate'                        => ['VideoFrameRate', 'com.apple.quicktime.videoFrameRate'],
        'HDRFormat'                             => ['HDRFormat', 'com.apple.quicktime.hdrFormat'],
        'TransferFunction'                      => ['TransferFunction', 'com.apple.quicktime.transferFunction'],
        'ColorPrimaries'                        => ['ColorPrimaries', 'com.apple.quicktime.colorPrimaries'],
        'AudioBitsPerChannel'                   => ['AudioBitsPerChannel', QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY],
    ];
    /**
     * Wraps an optional QuickTime metadata container for convenient lookups.
     *
     * @param QuickTimeMeta|null $meta Parsed QuickTime metadata aggregate.
     */
    public function __construct(private ?QuickTimeMeta $meta)
    {
    }

    /**
     * Reads a string value from the metadata map.
     */
    public function string(string $key): ?string
    {
        $value = $this->lookupValue($key);

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Reads an integer value from the metadata map.
     */
    public function int(string $key): ?int
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
     * Reads a float value from the metadata map.
     */
    public function float(string $key): ?float
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
     * Interprets the metadata value as a boolean.
     */
    public function bool(string $key): ?bool
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
     * Resolves a raw metadata value using the alias map.
     *
     * @return string|int|float|bool|null
     */
    private function lookupValue(string $key): string|int|float|bool|null
    {
        if ($this->meta === null) {
            return null;
        }

        $candidates = self::KEY_ALIASES[$key] ?? [$key];

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $this->meta->keys)) {
                return $this->meta->keys[$candidate];
            }
        }

        return null;
    }
}
