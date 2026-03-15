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
     * QuickTime metadata key exposing the movie creation date from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: creation_time as a formatted UTC date string.
     */
    public const string CREATE_DATE_KEY = 'com.apple.quicktime.creationDate';

    /**
     * QuickTime metadata key exposing the movie modification date from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: modification_time as a formatted UTC date string.
     */
    public const string MODIFY_DATE_KEY = 'com.apple.quicktime.modificationDate';

    /**
     * QuickTime metadata key exposing the movie duration in seconds from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: duration / timescale expressed as a float.
     */
    public const string DURATION_KEY = 'com.apple.quicktime.duration';

    /**
     * QuickTime metadata key exposing the movie timescale from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: number of time units per second.
     */
    public const string TIME_SCALE_KEY = 'com.apple.quicktime.timeScale';

    /**
     * QuickTime metadata key exposing the preferred playback rate from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: rate decoded from 16.16 fixed-point (1.0 = normal speed).
     */
    public const string PREFERRED_RATE_KEY = 'com.apple.quicktime.preferredRate';

    /**
     * QuickTime metadata key exposing the preferred playback volume from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: volume decoded from 8.8 fixed-point (1.0 = full volume).
     */
    public const string PREFERRED_VOLUME_KEY = 'com.apple.quicktime.preferredVolume';

    /**
     * QuickTime metadata key exposing the computed video frame rate.
     *
     * Derived from the stts (time-to-sample) box and the media timescale.
     */
    public const string VIDEO_FRAME_RATE_KEY = 'com.apple.quicktime.videoFrameRate';

    /**
     * QuickTime metadata key exposing the maximum video bitrate in bits per second.
     *
     * ISO/IEC 14496-12 §8.5.2.2: maxBitrate from the BitRateBox (btrt).
     */
    public const string VIDEO_MAX_BITRATE_KEY = 'com.apple.quicktime.videoMaxBitrate';

    /**
     * QuickTime metadata key exposing the average video bitrate in bits per second.
     *
     * ISO/IEC 14496-12 §8.5.2.2: avgBitrate from the BitRateBox (btrt).
     */
    public const string VIDEO_AVG_BITRATE_KEY = 'com.apple.quicktime.videoAvgBitrate';

    /**
     * QuickTime metadata key exposing the maximum audio bitrate in bits per second.
     *
     * ISO/IEC 14496-12 §8.5.2.2: maxBitrate from the BitRateBox (btrt).
     */
    public const string AUDIO_MAX_BITRATE_KEY = 'com.apple.quicktime.audioMaxBitrate';

    /**
     * QuickTime metadata key exposing the average audio bitrate in bits per second.
     *
     * ISO/IEC 14496-12 §8.5.2.2: avgBitrate from the BitRateBox (btrt).
     */
    public const string AUDIO_AVG_BITRATE_KEY = 'com.apple.quicktime.audioAvgBitrate';

    /**
     * QuickTime metadata key exposing the colour primaries code from the colr/nclx box.
     *
     * ISO/IEC 14496-12 §12.1.5.2: colour_primaries field of the nclx ColourInformationBox.
     */
    public const string COLOR_PRIMARIES_KEY = 'com.apple.quicktime.colorPrimaries';

    /**
     * QuickTime metadata key exposing the transfer characteristics code from the colr/nclx box.
     *
     * ISO/IEC 14496-12 §12.1.5.2: transfer_characteristics field of the nclx ColourInformationBox.
     */
    public const string TRANSFER_CHARACTERISTICS_KEY = 'com.apple.quicktime.transferCharacteristics';

    /**
     * QuickTime metadata key exposing the matrix coefficients code from the colr/nclx box.
     *
     * ISO/IEC 14496-12 §12.1.5.2: matrix_coefficients field of the nclx ColourInformationBox.
     */
    public const string MATRIX_COEFFICIENTS_KEY = 'com.apple.quicktime.matrixCoefficients';

    /**
     * QuickTime metadata key exposing the video full-range flag from the colr/nclx box.
     *
     * ISO/IEC 14496-12 §12.1.5.2: full_range_flag (bit 7) of the nclx ColourInformationBox.
     */
    public const string VIDEO_FULL_RANGE_FLAG_KEY = 'com.apple.quicktime.videoFullRangeFlag';

    /**
     * QuickTime metadata key exposing the metadata format code from a metadata handler track.
     *
     * Derived from the stsd sample-entry fourcc when the track handler type is 'meta'
     * (e.g. 'djmd' for DJI telemetry tracks).
     */
    public const string META_FORMAT_KEY = 'com.apple.quicktime.metaFormat';

    /**
     * Mapping of shorthand lookup keys to canonical QuickTime metadata identifiers.
     *
     * @var array<string, list<string>>
     */
    private const array KEY_ALIASES = [
        self::MAJOR_BRAND_KEY                     => [self::MAJOR_BRAND_KEY, 'MajorBrand'],
        'MajorBrand'                              => ['MajorBrand', self::MAJOR_BRAND_KEY],
        self::MINOR_VERSION_KEY                   => [self::MINOR_VERSION_KEY, 'MinorVersion'],
        'MinorVersion'                            => ['MinorVersion', self::MINOR_VERSION_KEY],
        self::COMPATIBLE_BRANDS_KEY               => [self::COMPATIBLE_BRANDS_KEY, 'CompatibleBrands'],
        'CompatibleBrands'                        => ['CompatibleBrands', self::COMPATIBLE_BRANDS_KEY],
        self::HANDLER_DESCRIPTION_KEY             => [self::HANDLER_DESCRIPTION_KEY, 'HandlerDescription'],
        'HandlerDescription'                      => ['HandlerDescription', self::HANDLER_DESCRIPTION_KEY],
        self::VIDEO_WIDTH_KEY                     => [self::VIDEO_WIDTH_KEY, 'ImageWidth', 'VideoWidth'],
        'ImageWidth'                              => ['ImageWidth', self::VIDEO_WIDTH_KEY, 'VideoWidth'],
        self::VIDEO_HEIGHT_KEY                    => [self::VIDEO_HEIGHT_KEY, 'ImageHeight', 'VideoHeight'],
        'ImageHeight'                             => ['ImageHeight', self::VIDEO_HEIGHT_KEY, 'VideoHeight'],
        self::VIDEO_HORIZONTAL_RESOLUTION_KEY     => [self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'VideoHorizontalResolution', 'HorizontalResolution'],
        'VideoHorizontalResolution'               => ['VideoHorizontalResolution', self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'HorizontalResolution'],
        'HorizontalResolution'                    => ['HorizontalResolution', self::VIDEO_HORIZONTAL_RESOLUTION_KEY, 'VideoHorizontalResolution'],
        self::VIDEO_VERTICAL_RESOLUTION_KEY       => [self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VideoVerticalResolution', 'VerticalResolution'],
        'VideoVerticalResolution'                 => ['VideoVerticalResolution', self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VerticalResolution'],
        'VerticalResolution'                      => ['VerticalResolution', self::VIDEO_VERTICAL_RESOLUTION_KEY, 'VideoVerticalResolution'],
        self::VIDEO_FRAME_COUNT_KEY               => [self::VIDEO_FRAME_COUNT_KEY, 'VideoFrameCount'],
        'VideoFrameCount'                         => ['VideoFrameCount', self::VIDEO_FRAME_COUNT_KEY],
        self::VIDEO_CODEC_KEY                     => [self::VIDEO_CODEC_KEY, 'CompressorID', 'VideoCodecID'],
        'CompressorID'                            => ['CompressorID', self::VIDEO_CODEC_KEY, 'VideoCodecID'],
        self::COMPRESSOR_NAME_KEY                 => [self::COMPRESSOR_NAME_KEY, 'CompressorName'],
        'CompressorName'                          => ['CompressorName', self::COMPRESSOR_NAME_KEY],
        self::VIDEO_FRAME_RATE_KEY                => [self::VIDEO_FRAME_RATE_KEY, 'VideoFrameRate'],
        'VideoFrameRate'                          => ['VideoFrameRate', self::VIDEO_FRAME_RATE_KEY],
        self::VIDEO_MAX_BITRATE_KEY               => [self::VIDEO_MAX_BITRATE_KEY, 'VideoMaxBitrate'],
        'VideoMaxBitrate'                         => ['VideoMaxBitrate', self::VIDEO_MAX_BITRATE_KEY],
        self::VIDEO_AVG_BITRATE_KEY               => [self::VIDEO_AVG_BITRATE_KEY, 'VideoAvgBitrate'],
        'VideoAvgBitrate'                         => ['VideoAvgBitrate', self::VIDEO_AVG_BITRATE_KEY],
        self::COLOR_PRIMARIES_KEY                 => [self::COLOR_PRIMARIES_KEY, 'ColorPrimaries'],
        'ColorPrimaries'                          => ['ColorPrimaries', self::COLOR_PRIMARIES_KEY],
        self::TRANSFER_CHARACTERISTICS_KEY        => [self::TRANSFER_CHARACTERISTICS_KEY, 'TransferCharacteristics'],
        'TransferCharacteristics'                 => ['TransferCharacteristics', self::TRANSFER_CHARACTERISTICS_KEY],
        self::MATRIX_COEFFICIENTS_KEY             => [self::MATRIX_COEFFICIENTS_KEY, 'MatrixCoefficients'],
        'MatrixCoefficients'                      => ['MatrixCoefficients', self::MATRIX_COEFFICIENTS_KEY],
        self::VIDEO_FULL_RANGE_FLAG_KEY           => [self::VIDEO_FULL_RANGE_FLAG_KEY, 'VideoFullRangeFlag'],
        'VideoFullRangeFlag'                      => ['VideoFullRangeFlag', self::VIDEO_FULL_RANGE_FLAG_KEY],
        self::AUDIO_FORMAT_KEY                    => [self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioFormat', 'AudioCodecID'],
        self::AUDIO_CODEC_KEY                     => [self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioCodecID', 'AudioFormat'],
        'AudioFormat'                             => ['AudioFormat', self::AUDIO_FORMAT_KEY, self::AUDIO_CODEC_KEY, 'AudioCodecID'],
        'AudioCodecID'                            => ['AudioCodecID', self::AUDIO_CODEC_KEY, self::AUDIO_FORMAT_KEY, 'AudioFormat'],
        self::AUDIO_CHANNELS_KEY                  => [self::AUDIO_CHANNELS_KEY, 'AudioChannels'],
        'AudioChannels'                           => ['AudioChannels', self::AUDIO_CHANNELS_KEY],
        self::AUDIO_SAMPLE_RATE_KEY               => [self::AUDIO_SAMPLE_RATE_KEY, 'AudioSampleRate'],
        'AudioSampleRate'                         => ['AudioSampleRate', self::AUDIO_SAMPLE_RATE_KEY],
        self::AUDIO_BITS_PER_SAMPLE_KEY           => [self::AUDIO_BITS_PER_SAMPLE_KEY, 'AudioBitsPerSample'],
        'AudioBitsPerSample'                      => ['AudioBitsPerSample', self::AUDIO_BITS_PER_SAMPLE_KEY],
        self::AUDIO_MAX_BITRATE_KEY               => [self::AUDIO_MAX_BITRATE_KEY, 'AudioMaxBitrate'],
        'AudioMaxBitrate'                         => ['AudioMaxBitrate', self::AUDIO_MAX_BITRATE_KEY],
        self::AUDIO_AVG_BITRATE_KEY               => [self::AUDIO_AVG_BITRATE_KEY, 'AudioAvgBitrate'],
        'AudioAvgBitrate'                         => ['AudioAvgBitrate', self::AUDIO_AVG_BITRATE_KEY],
        self::AUDIO_LPCM_FORMAT_FLAGS_KEY         => [self::AUDIO_LPCM_FORMAT_FLAGS_KEY, 'AudioLpcmFormatFlags'],
        'AudioLpcmFormatFlags'                    => ['AudioLpcmFormatFlags', self::AUDIO_LPCM_FORMAT_FLAGS_KEY],
        self::AUDIO_LPCM_NUMERIC_FORMAT_KEY       => [self::AUDIO_LPCM_NUMERIC_FORMAT_KEY, 'AudioLpcmNumericFormat'],
        'AudioLpcmNumericFormat'                  => ['AudioLpcmNumericFormat', self::AUDIO_LPCM_NUMERIC_FORMAT_KEY],
        self::AUDIO_LPCM_ENDIANNESS_KEY           => [self::AUDIO_LPCM_ENDIANNESS_KEY, 'AudioLpcmEndianness'],
        'AudioLpcmEndianness'                     => ['AudioLpcmEndianness', self::AUDIO_LPCM_ENDIANNESS_KEY],
        self::AUDIO_LPCM_PACKING_KEY              => [self::AUDIO_LPCM_PACKING_KEY, 'AudioLpcmPacking'],
        'AudioLpcmPacking'                        => ['AudioLpcmPacking', self::AUDIO_LPCM_PACKING_KEY],
        self::AUDIO_LPCM_IS_FLOAT_KEY             => [self::AUDIO_LPCM_IS_FLOAT_KEY, 'AudioLpcmIsFloat'],
        'AudioLpcmIsFloat'                        => ['AudioLpcmIsFloat', self::AUDIO_LPCM_IS_FLOAT_KEY],
        self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY    => [self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY, 'AudioLpcmIsSignedInteger'],
        'AudioLpcmIsSignedInteger'                => ['AudioLpcmIsSignedInteger', self::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY],
        self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY        => [self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY, 'AudioLpcmIsBigEndian'],
        'AudioLpcmIsBigEndian'                    => ['AudioLpcmIsBigEndian', self::AUDIO_LPCM_IS_BIG_ENDIAN_KEY],
        self::AUDIO_LPCM_IS_PACKED_KEY            => [self::AUDIO_LPCM_IS_PACKED_KEY, 'AudioLpcmIsPacked'],
        'AudioLpcmIsPacked'                       => ['AudioLpcmIsPacked', self::AUDIO_LPCM_IS_PACKED_KEY],
        self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY      => [self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY, 'AudioLpcmIsAlignedHigh'],
        'AudioLpcmIsAlignedHigh'                  => ['AudioLpcmIsAlignedHigh', self::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY],
        self::AUDIO_LPCM_BYTES_PER_PACKET_KEY     => [self::AUDIO_LPCM_BYTES_PER_PACKET_KEY, 'AudioLpcmBytesPerPacket'],
        'AudioLpcmBytesPerPacket'                 => ['AudioLpcmBytesPerPacket', self::AUDIO_LPCM_BYTES_PER_PACKET_KEY],
        self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY    => [self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY, 'AudioLpcmFramesPerPacket'],
        'AudioLpcmFramesPerPacket'                => ['AudioLpcmFramesPerPacket', self::AUDIO_LPCM_FRAMES_PER_PACKET_KEY],
        self::CREATE_DATE_KEY                     => [self::CREATE_DATE_KEY, 'CreateDate'],
        'CreateDate'                              => ['CreateDate', self::CREATE_DATE_KEY],
        self::MODIFY_DATE_KEY                     => [self::MODIFY_DATE_KEY, 'ModifyDate'],
        'ModifyDate'                              => ['ModifyDate', self::MODIFY_DATE_KEY],
        self::DURATION_KEY                        => [self::DURATION_KEY, 'Duration'],
        'Duration'                                => ['Duration', self::DURATION_KEY],
        self::TIME_SCALE_KEY                      => [self::TIME_SCALE_KEY, 'TimeScale'],
        'TimeScale'                               => ['TimeScale', self::TIME_SCALE_KEY],
        self::PREFERRED_RATE_KEY                  => [self::PREFERRED_RATE_KEY, 'PreferredRate'],
        'PreferredRate'                           => ['PreferredRate', self::PREFERRED_RATE_KEY],
        self::PREFERRED_VOLUME_KEY                => [self::PREFERRED_VOLUME_KEY, 'PreferredVolume'],
        'PreferredVolume'                         => ['PreferredVolume', self::PREFERRED_VOLUME_KEY],
        self::META_FORMAT_KEY                     => [self::META_FORMAT_KEY, 'MetaFormat'],
        'MetaFormat'                              => ['MetaFormat', self::META_FORMAT_KEY],
        'Encoder'                                 => ['Encoder', 'com.apple.quicktime.encoder', 'com.apple.quicktime.software'],
        'AvgBitrate'                              => ['AvgBitrate', 'com.apple.quicktime.avgBitrate'],
        'Bitrate'                                 => ['Bitrate', 'com.apple.quicktime.bitrate', 'com.apple.quicktime.dataRate'],
        'HDRFormat'                               => ['HDRFormat', 'com.apple.quicktime.hdrFormat'],
        'TransferFunction'                        => ['TransferFunction', 'com.apple.quicktime.transferFunction'],
        'AudioBitsPerChannel'                     => ['AudioBitsPerChannel', self::AUDIO_BITS_PER_SAMPLE_KEY],
        self::TRACK_NAME_KEY                      => [self::TRACK_NAME_KEY, 'TrackName'],
        'TrackName'                               => ['TrackName', self::TRACK_NAME_KEY],
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
