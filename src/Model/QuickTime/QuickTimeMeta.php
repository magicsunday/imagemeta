<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\QuickTime;

use function array_find;
use function array_key_exists;
use function in_array;
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
     * QuickTime metadata key exposing horizontal video resolution in pixels per inch.
     */
    public const string VIDEO_HORIZONTAL_RESOLUTION_KEY = 'com.apple.quicktime.videoHorizontalResolution';

    /**
     * QuickTime metadata key exposing vertical video resolution in pixels per inch.
     */
    public const string VIDEO_VERTICAL_RESOLUTION_KEY = 'com.apple.quicktime.videoVerticalResolution';

    /**
     * QuickTime metadata key exposing video sample-entry frame count for the primary track.
     */
    public const string VIDEO_FRAME_COUNT_KEY = 'com.apple.quicktime.videoFrameCount';

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
     * QuickTime metadata key exposing LPCM format flags from SoundSampleDescription v2.
     */
    public const string AUDIO_LPCM_FORMAT_FLAGS_KEY = 'com.apple.quicktime.audioLpcmFormatFlags';

    /**
     * QuickTime metadata key exposing LPCM numeric format (float or integer).
     */
    public const string AUDIO_LPCM_NUMERIC_FORMAT_KEY = 'com.apple.quicktime.audioLpcmNumericFormat';

    /**
     * QuickTime metadata key exposing LPCM byte order (big or little).
     */
    public const string AUDIO_LPCM_ENDIANNESS_KEY = 'com.apple.quicktime.audioLpcmEndianness';

    /**
     * QuickTime metadata key exposing LPCM sample packing mode (packed or aligned).
     */
    public const string AUDIO_LPCM_PACKING_KEY = 'com.apple.quicktime.audioLpcmPacking';

    /**
     * QuickTime metadata key exposing whether LPCM samples are floating point.
     */
    public const string AUDIO_LPCM_IS_FLOAT_KEY = 'com.apple.quicktime.audioLpcmIsFloat';

    /**
     * QuickTime metadata key exposing whether LPCM integer samples are signed.
     */
    public const string AUDIO_LPCM_IS_SIGNED_INTEGER_KEY = 'com.apple.quicktime.audioLpcmIsSignedInteger';

    /**
     * QuickTime metadata key exposing whether LPCM samples are big-endian.
     */
    public const string AUDIO_LPCM_IS_BIG_ENDIAN_KEY = 'com.apple.quicktime.audioLpcmIsBigEndian';

    /**
     * QuickTime metadata key exposing whether LPCM samples are packed.
     */
    public const string AUDIO_LPCM_IS_PACKED_KEY = 'com.apple.quicktime.audioLpcmIsPacked';

    /**
     * QuickTime metadata key exposing whether LPCM aligned samples are high-aligned.
     */
    public const string AUDIO_LPCM_IS_ALIGNED_HIGH_KEY = 'com.apple.quicktime.audioLpcmIsAlignedHigh';

    /**
     * QuickTime metadata key exposing LPCM bytes per audio packet.
     */
    public const string AUDIO_LPCM_BYTES_PER_PACKET_KEY = 'com.apple.quicktime.audioLpcmBytesPerPacket';

    /**
     * QuickTime metadata key exposing LPCM frames per audio packet.
     */
    public const string AUDIO_LPCM_FRAMES_PER_PACKET_KEY = 'com.apple.quicktime.audioLpcmFramesPerPacket';

    /**
     * QuickTime metadata key exposing the track name from a track-level udta name atom.
     */
    public const string TRACK_NAME_KEY = 'com.apple.quicktime.trackName';

    /**
     * Mapping of shorthand lookup keys to canonical QuickTime metadata identifiers.
     *
     * @var array<string, list<string>>
     */
    private const array KEY_ALIASES = [
        self::MAJOR_BRAND_KEY                  => [self::MAJOR_BRAND_KEY, 'MajorBrand'],
        'MajorBrand'                           => ['MajorBrand', self::MAJOR_BRAND_KEY],
        self::MINOR_VERSION_KEY                => [self::MINOR_VERSION_KEY, 'MinorVersion'],
        'MinorVersion'                         => ['MinorVersion', self::MINOR_VERSION_KEY],
        self::COMPATIBLE_BRANDS_KEY            => [self::COMPATIBLE_BRANDS_KEY, 'CompatibleBrands'],
        'CompatibleBrands'                     => ['CompatibleBrands', self::COMPATIBLE_BRANDS_KEY],
        self::HANDLER_DESCRIPTION_KEY          => [self::HANDLER_DESCRIPTION_KEY, 'HandlerDescription'],
        'HandlerDescription'                   => ['HandlerDescription', self::HANDLER_DESCRIPTION_KEY],
        self::VIDEO_WIDTH_KEY                  => [self::VIDEO_WIDTH_KEY, 'ImageWidth', 'VideoWidth'],
        'ImageWidth'                           => ['ImageWidth', self::VIDEO_WIDTH_KEY, 'VideoWidth'],
        self::VIDEO_HEIGHT_KEY                 => [self::VIDEO_HEIGHT_KEY, 'ImageHeight', 'VideoHeight'],
        'ImageHeight'                          => ['ImageHeight', self::VIDEO_HEIGHT_KEY, 'VideoHeight'],
        self::VIDEO_HORIZONTAL_RESOLUTION_KEY  => [self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'VideoHorizontalResolution', 'HorizontalResolution'],
        'VideoHorizontalResolution'            => ['VideoHorizontalResolution', self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'HorizontalResolution'],
        'HorizontalResolution'                 => ['HorizontalResolution', self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'VideoHorizontalResolution'],
        self::VIDEO_VERTICAL_RESOLUTION_KEY    => [self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VideoVerticalResolution', 'VerticalResolution'],
        'VideoVerticalResolution'              => ['VideoVerticalResolution', self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VerticalResolution'],
        'VerticalResolution'                   => ['VerticalResolution', self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VideoVerticalResolution'],
        self::VIDEO_FRAME_COUNT_KEY            => [self::VIDEO_FRAME_COUNT_KEY, 'VideoFrameCount'],
        'VideoFrameCount'                      => ['VideoFrameCount', self::VIDEO_FRAME_COUNT_KEY],
        self::VIDEO_CODEC_KEY                  => [self::VIDEO_CODEC_KEY, 'CompressorID', 'VideoCodecID'],
        'CompressorID'                         => ['CompressorID', self::VIDEO_CODEC_KEY, 'VideoCodecID'],
        self::COMPRESSOR_NAME_KEY              => [self::COMPRESSOR_NAME_KEY, 'CompressorName'],
        'CompressorName'                       => ['CompressorName', self::COMPRESSOR_NAME_KEY],
        self::AUDIO_FORMAT_KEY                 => [self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioFormat', 'AudioCodecID'],
        self::AUDIO_CODEC_KEY                  => [self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioCodecID', 'AudioFormat'],
        'AudioFormat'                          => ['AudioFormat', self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioCodecID'],
        'AudioCodecID'                         => ['AudioCodecID', self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioFormat'],
        self::AUDIO_CHANNELS_KEY               => [self::AUDIO_CHANNELS_KEY, 'AudioChannels'],
        'AudioChannels'                        => ['AudioChannels', self::AUDIO_CHANNELS_KEY],
        self::AUDIO_SAMPLE_RATE_KEY            => [self::AUDIO_SAMPLE_RATE_KEY, 'AudioSampleRate'],
        'AudioSampleRate'                      => ['AudioSampleRate', self::AUDIO_SAMPLE_RATE_KEY],
        self::AUDIO_BITS_PER_SAMPLE_KEY        => [self::AUDIO_BITS_PER_SAMPLE_KEY, 'AudioBitsPerSample'],
        'AudioBitsPerSample'                   => ['AudioBitsPerSample', self::AUDIO_BITS_PER_SAMPLE_KEY],
        self::AUDIO_LPCM_FORMAT_FLAGS_KEY      => [self::AUDIO_LPCM_FORMAT_FLAGS_KEY, 'AudioLpcmFormatFlags'],
        'AudioLpcmFormatFlags'                 => ['AudioLpcmFormatFlags', self::AUDIO_LPCM_FORMAT_FLAGS_KEY],
        self::AUDIO_LPCM_NUMERIC_FORMAT_KEY    => [self::AUDIO_LPCM_NUMERIC_FORMAT_KEY, 'AudioLpcmNumericFormat'],
        'AudioLpcmNumericFormat'               => ['AudioLpcmNumericFormat', self::AUDIO_LPCM_NUMERIC_FORMAT_KEY],
        self::AUDIO_LPCM_ENDIANNESS_KEY        => [self::AUDIO_LPCM_ENDIANNESS_KEY, 'AudioLpcmEndianness'],
        'AudioLpcmEndianness'                  => ['AudioLpcmEndianness', self::AUDIO_LPCM_ENDIANNESS_KEY],
        self::AUDIO_LPCM_PACKING_KEY           => [self::AUDIO_LPCM_PACKING_KEY, 'AudioLpcmPacking'],
        'AudioLpcmPacking'                     => ['AudioLpcmPacking', self::AUDIO_LPCM_PACKING_KEY],
        self::AUDIO_LPCM_IS_FLOAT_KEY          => [self::AUDIO_LPCM_IS_FLOAT_KEY, 'AudioLpcmIsFloat'],
        'AudioLpcmIsFloat'                     => ['AudioLpcmIsFloat', self::AUDIO_LPCM_IS_FLOAT_KEY],
        self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY => [self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY, 'AudioLpcmIsSignedInteger'],
        'AudioLpcmIsSignedInteger'             => ['AudioLpcmIsSignedInteger', self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY],
        self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY     => [self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY, 'AudioLpcmIsBigEndian'],
        'AudioLpcmIsBigEndian'                 => ['AudioLpcmIsBigEndian', self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY],
        self::AUDIO_LPCM_IS_PACKED_KEY         => [self::AUDIO_LPCM_IS_PACKED_KEY, 'AudioLpcmIsPacked'],
        'AudioLpcmIsPacked'                    => ['AudioLpcmIsPacked', self::AUDIO_LPCM_IS_PACKED_KEY],
        self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY   => [self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY, 'AudioLpcmIsAlignedHigh'],
        'AudioLpcmIsAlignedHigh'               => ['AudioLpcmIsAlignedHigh', self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY],
        self::AUDIO_LPCM_BYTES_PER_PACKET_KEY  => [self::AUDIO_LPCM_BYTES_PER_PACKET_KEY, 'AudioLpcmBytesPerPacket'],
        'AudioLpcmBytesPerPacket'              => ['AudioLpcmBytesPerPacket', self::AUDIO_LPCM_BYTES_PER_PACKET_KEY],
        self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY => [self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY, 'AudioLpcmFramesPerPacket'],
        'AudioLpcmFramesPerPacket'             => ['AudioLpcmFramesPerPacket', self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY],
        'Encoder'                              => ['Encoder', 'com.apple.quicktime.encoder'],
        'AvgBitrate'                           => ['AvgBitrate', 'com.apple.quicktime.avgBitrate'],
        'Bitrate'                              => ['Bitrate', 'com.apple.quicktime.bitrate', 'com.apple.quicktime.dataRate'],
        'Duration'                             => ['Duration', 'com.apple.quicktime.duration'],
        'VideoFrameRate'                       => ['VideoFrameRate', 'com.apple.quicktime.videoFrameRate'],
        'HDRFormat'                            => ['HDRFormat', 'com.apple.quicktime.hdrFormat'],
        'TransferFunction'                     => ['TransferFunction', 'com.apple.quicktime.transferFunction'],
        'ColorPrimaries'                       => ['ColorPrimaries', 'com.apple.quicktime.colorPrimaries'],
        'AudioBitsPerChannel'                  => ['AudioBitsPerChannel', self::AUDIO_BITS_PER_SAMPLE_KEY],
        self::TRACK_NAME_KEY                   => [self::TRACK_NAME_KEY, 'TrackName'],
        'TrackName'                            => ['TrackName', self::TRACK_NAME_KEY],
    ];

    /** @var array<string, string|int|float|bool> Map of QuickTime metadata keys and their values (first value per key). */
    public array $keys;

    /** @var array<string, list<QuickTimeDataAtom>> All data value atoms per key, preserving type, locale, and order. */
    public array $dataAtoms;

    /**
     * Creates a new instance of QuickTime metadata information.
     *
     * @param array<string, string|int|float|bool>   $keys      Map of QuickTime metadata keys and their values (first value per key).
     * @param array<string, list<QuickTimeDataAtom>> $dataAtoms All data value atoms per key, preserving type, locale, and order.
     */
    public function __construct(
        array $keys,
        array $dataAtoms = [],
    ) {
        $this->keys      = [...$keys];
        $this->dataAtoms = [...$dataAtoms];
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
     * Returns all data value atoms for the given metadata key, resolving aliases.
     *
     * @return list<QuickTimeDataAtom>
     */
    public function allValues(string $key): array
    {
        $candidates = self::KEY_ALIASES[$key] ?? [$key];

        $resolvedKey = array_find(
            $candidates,
            fn (string $candidate): bool => array_key_exists($candidate, $this->dataAtoms)
        );

        return $resolvedKey !== null ? $this->dataAtoms[$resolvedKey] : [];
    }

    /**
     * Returns the first atom acceptable for locale/type constraints in source order.
     *
     * QuickTime File Format 2012, "Data Ordering" (p. 142): values are ordered
     * from most-specific to most-general, so the first acceptable atom is the
     * deterministic selection for a given locale/type acceptance set.
     *
     * @param string    $key                    QuickTime metadata key or alias.
     * @param list<int> $acceptedLocales        Accepted locale indicators; empty means any.
     * @param list<int> $acceptedTypeIndicators Accepted type indicators; empty means any.
     */
    public function firstAcceptableAtom(string $key, array $acceptedLocales = [], array $acceptedTypeIndicators = []): ?QuickTimeDataAtom
    {
        return array_find(
            $this->allValues($key),
            static fn (QuickTimeDataAtom $atom): bool => (($acceptedLocales === []) || in_array($atom->locale, $acceptedLocales, true))
                && (($acceptedTypeIndicators === []) || in_array($atom->typeIndicator, $acceptedTypeIndicators, true)),
        );
    }

    /**
     * Returns the value of the first acceptable atom for locale/type constraints.
     *
     * @param string    $key                    QuickTime metadata key or alias.
     * @param list<int> $acceptedLocales        Accepted locale indicators; empty means any.
     * @param list<int> $acceptedTypeIndicators Accepted type indicators; empty means any.
     */
    public function firstAcceptableValue(string $key, array $acceptedLocales = [], array $acceptedTypeIndicators = []): string|int|float|bool|null
    {
        $atom = $this->firstAcceptableAtom($key, $acceptedLocales, $acceptedTypeIndicators);

        return $atom?->value;
    }

    /**
     * Resolves the first available value for the given metadata key or its aliases.
     */
    private function lookupValue(string $key): string|int|float|bool|null
    {
        $candidates = self::KEY_ALIASES[$key] ?? [$key];

        $resolvedKey = array_find(
            $candidates,
            fn (string $candidate): bool => array_key_exists($candidate, $this->keys)
        );

        return $resolvedKey !== null ? $this->keys[$resolvedKey] : null;
    }

    /**
     * Returns the QuickTime content identifier value when available.
     */
    public function contentIdentifier(): ?string
    {
        $key = self::CONTENT_IDENTIFIER_KEY;

        return isset($this->keys[$key]) ? (string) $this->keys[$key] : null;
    }
}
