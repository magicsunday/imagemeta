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
    public const string CONTENT_IDENTIFIER_KEY           = 'com.apple.quicktime.content.identifier';

    /**
     * QuickTime metadata key representing the declared container major brand.
     */
    public const string MAJOR_BRAND_KEY                  = 'com.apple.quicktime.majorBrand';

    /**
     * QuickTime metadata key exposing the minor version of the container brand.
     */
    public const string MINOR_VERSION_KEY                = 'com.apple.quicktime.minorVersion';

    /**
     * QuickTime metadata key listing compatible brands.
     */
    public const string COMPATIBLE_BRANDS_KEY            = 'com.apple.quicktime.compatibleBrands';

    /**
     * QuickTime metadata key describing the handler for a media track.
     */
    public const string HANDLER_DESCRIPTION_KEY          = 'com.apple.quicktime.handlerDescription';

    /**
     * QuickTime metadata key exposing the display width of the primary video track.
     */
    public const string VIDEO_WIDTH_KEY                  = 'com.apple.quicktime.videoWidth';

    /**
     * QuickTime metadata key exposing the display height of the primary video track.
     */
    public const string VIDEO_HEIGHT_KEY                 = 'com.apple.quicktime.videoHeight';

    /**
     * QuickTime metadata key exposing horizontal video resolution in pixels per inch.
     */
    public const string VIDEO_HORIZONTAL_RESOLUTION_KEY  = 'com.apple.quicktime.videoHorizontalResolution';

    /**
     * QuickTime metadata key exposing vertical video resolution in pixels per inch.
     */
    public const string VIDEO_VERTICAL_RESOLUTION_KEY    = 'com.apple.quicktime.videoVerticalResolution';

    /**
     * QuickTime metadata key exposing video sample-entry frame count for the primary track.
     */
    public const string VIDEO_FRAME_COUNT_KEY            = 'com.apple.quicktime.videoFrameCount';

    /**
     * QuickTime metadata key describing the codec four-character code for video.
     */
    public const string VIDEO_CODEC_KEY                  = 'com.apple.quicktime.videoCodec';

    /**
     * QuickTime metadata key exposing the human readable compressor name.
     */
    public const string COMPRESSOR_NAME_KEY              = 'com.apple.quicktime.compressorName';

    /**
     * QuickTime metadata key describing the audio format four-character code.
     */
    public const string AUDIO_FORMAT_KEY                 = 'com.apple.quicktime.audioFormat';

    /**
     * QuickTime metadata key describing the audio codec identifier.
     */
    public const string AUDIO_CODEC_KEY                  = 'com.apple.quicktime.audioCodec';

    /**
     * QuickTime metadata key exposing the audio channel count.
     */
    public const string AUDIO_CHANNELS_KEY               = 'com.apple.quicktime.audioChannels';

    /**
     * QuickTime metadata key exposing the audio sample rate in Hz.
     */
    public const string AUDIO_SAMPLE_RATE_KEY            = 'com.apple.quicktime.audioSampleRate';

    /**
     * QuickTime metadata key exposing the audio bit depth per sample.
     */
    public const string AUDIO_BITS_PER_SAMPLE_KEY        = 'com.apple.quicktime.audioBitsPerSample';

    /**
     * QuickTime metadata key exposing LPCM format flags from SoundSampleDescription v2.
     */
    public const string AUDIO_LPCM_FORMAT_FLAGS_KEY      = 'com.apple.quicktime.audioLpcmFormatFlags';

    /**
     * QuickTime metadata key exposing LPCM numeric format (float or integer).
     */
    public const string AUDIO_LPCM_NUMERIC_FORMAT_KEY    = 'com.apple.quicktime.audioLpcmNumericFormat';

    /**
     * QuickTime metadata key exposing LPCM byte order (big or little).
     */
    public const string AUDIO_LPCM_ENDIANNESS_KEY        = 'com.apple.quicktime.audioLpcmEndianness';

    /**
     * QuickTime metadata key exposing LPCM sample packing mode (packed or aligned).
     */
    public const string AUDIO_LPCM_PACKING_KEY           = 'com.apple.quicktime.audioLpcmPacking';

    /**
     * QuickTime metadata key exposing whether LPCM samples are floating point.
     */
    public const string AUDIO_LPCM_IS_FLOAT_KEY          = 'com.apple.quicktime.audioLpcmIsFloat';

    /**
     * QuickTime metadata key exposing whether LPCM integer samples are signed.
     */
    public const string AUDIO_LPCM_IS_SIGNED_INTEGER_KEY = 'com.apple.quicktime.audioLpcmIsSignedInteger';

    /**
     * QuickTime metadata key exposing whether LPCM samples are big-endian.
     */
    public const string AUDIO_LPCM_IS_BIG_ENDIAN_KEY     = 'com.apple.quicktime.audioLpcmIsBigEndian';

    /**
     * QuickTime metadata key exposing whether LPCM samples are packed.
     */
    public const string AUDIO_LPCM_IS_PACKED_KEY         = 'com.apple.quicktime.audioLpcmIsPacked';

    /**
     * QuickTime metadata key exposing whether LPCM aligned samples are high-aligned.
     */
    public const string AUDIO_LPCM_IS_ALIGNED_HIGH_KEY   = 'com.apple.quicktime.audioLpcmIsAlignedHigh';

    /**
     * QuickTime metadata key exposing LPCM bytes per audio packet.
     */
    public const string AUDIO_LPCM_BYTES_PER_PACKET_KEY  = 'com.apple.quicktime.audioLpcmBytesPerPacket';

    /**
     * QuickTime metadata key exposing LPCM frames per audio packet.
     */
    public const string AUDIO_LPCM_FRAMES_PER_PACKET_KEY = 'com.apple.quicktime.audioLpcmFramesPerPacket';

    /**
     * QuickTime metadata key exposing the track name from a track-level udta name atom.
     */
    public const string TRACK_NAME_KEY                   = 'com.apple.quicktime.trackName';

    /**
     * QuickTime metadata key exposing the movie creation timestamp (Mac epoch).
     */
    public const string CREATE_DATE_KEY                  = 'com.apple.quicktime.creationDate.mvhd';

    /**
     * QuickTime metadata key exposing the movie modification timestamp (Mac epoch).
     */
    public const string MODIFY_DATE_KEY                  = 'com.apple.quicktime.modificationDate.mvhd';

    /**
     * QuickTime metadata key exposing the movie duration in timescale units.
     */
    public const string DURATION_KEY                     = 'com.apple.quicktime.duration';

    /**
     * QuickTime metadata key exposing the movie timescale (time units per second).
     */
    public const string TIME_SCALE_KEY                   = 'com.apple.quicktime.timeScale';

    /**
     * QuickTime metadata key exposing the preferred playback rate (1.0 = normal).
     */
    public const string PREFERRED_RATE_KEY               = 'com.apple.quicktime.preferredRate';

    /**
     * QuickTime metadata key exposing the preferred playback volume (1.0 = full).
     */
    public const string PREFERRED_VOLUME_KEY             = 'com.apple.quicktime.preferredVolume';

    /**
     * QuickTime metadata key exposing the movie-level matrix structure.
     */
    public const string MATRIX_STRUCTURE_KEY             = 'com.apple.quicktime.matrixStructure';

    /**
     * QuickTime metadata key exposing the next available track ID.
     */
    public const string NEXT_TRACK_ID_KEY                = 'com.apple.quicktime.nextTrackID';

    /**
     * QuickTime metadata key exposing preview time in movie timescale.
     */
    public const string PREVIEW_TIME_KEY                 = 'com.apple.quicktime.previewTime';

    /**
     * QuickTime metadata key exposing preview duration in movie timescale.
     */
    public const string PREVIEW_DURATION_KEY             = 'com.apple.quicktime.previewDuration';

    /**
     * QuickTime metadata key exposing poster time in movie timescale.
     */
    public const string POSTER_TIME_KEY                  = 'com.apple.quicktime.posterTime';

    /**
     * QuickTime metadata key exposing selection start time in movie timescale.
     */
    public const string SELECTION_TIME_KEY               = 'com.apple.quicktime.selectionTime';

    /**
     * QuickTime metadata key exposing selection duration in movie timescale.
     */
    public const string SELECTION_DURATION_KEY           = 'com.apple.quicktime.selectionDuration';

    /**
     * QuickTime metadata key exposing current time position in movie timescale.
     */
    public const string CURRENT_TIME_KEY                 = 'com.apple.quicktime.currentTime';

    /**
     * QuickTime metadata key exposing track creation timestamp (Mac epoch).
     */
    public const string TRACK_CREATE_DATE_KEY            = 'com.apple.quicktime.trackCreationDate';

    /**
     * QuickTime metadata key exposing track modification timestamp (Mac epoch).
     */
    public const string TRACK_MODIFY_DATE_KEY            = 'com.apple.quicktime.trackModificationDate';

    /**
     * QuickTime metadata key exposing the track ID.
     */
    public const string TRACK_ID_KEY                     = 'com.apple.quicktime.trackID';

    /**
     * QuickTime metadata key exposing track duration in movie timescale.
     */
    public const string TRACK_DURATION_KEY               = 'com.apple.quicktime.trackDuration';

    /**
     * QuickTime metadata key exposing track spatial layer ordering.
     */
    public const string TRACK_LAYER_KEY                  = 'com.apple.quicktime.trackLayer';

    /**
     * QuickTime metadata key exposing track volume (8.8 fixed-point, 1.0 = full).
     */
    public const string TRACK_VOLUME_KEY                 = 'com.apple.quicktime.trackVolume';

    /**
     * QuickTime metadata key exposing rotation degrees derived from the tkhd matrix.
     */
    public const string ROTATION_KEY                     = 'com.apple.quicktime.rotation';

    /**
     * QuickTime metadata key exposing the track-level matrix structure.
     */
    public const string TRACK_MATRIX_KEY                 = 'com.apple.quicktime.trackMatrixStructure';

    /**
     * QuickTime metadata key exposing media creation timestamp (Mac epoch).
     */
    public const string MEDIA_CREATE_DATE_KEY            = 'com.apple.quicktime.mediaCreationDate';

    /**
     * QuickTime metadata key exposing media modification timestamp (Mac epoch).
     */
    public const string MEDIA_MODIFY_DATE_KEY            = 'com.apple.quicktime.mediaModificationDate';

    /**
     * QuickTime metadata key exposing media duration in media timescale.
     */
    public const string MEDIA_DURATION_KEY               = 'com.apple.quicktime.mediaDuration';

    /**
     * QuickTime metadata key exposing media timescale (time units per second).
     */
    public const string MEDIA_TIME_SCALE_KEY             = 'com.apple.quicktime.mediaTimeScale';

    /**
     * QuickTime metadata key exposing the packed ISO 639-2/T media language code.
     */
    public const string MEDIA_LANGUAGE_CODE_KEY          = 'com.apple.quicktime.mediaLanguageCode';

    /**
     * QuickTime metadata key exposing video sample entry bit depth.
     */
    public const string VIDEO_BIT_DEPTH_KEY              = 'com.apple.quicktime.videoBitDepth';

    /**
     * QuickTime metadata key exposing video media graphics mode from vmhd.
     */
    public const string GRAPHICS_MODE_KEY                = 'com.apple.quicktime.graphicsMode';

    /**
     * QuickTime metadata key exposing video media operation color from vmhd.
     */
    public const string OP_COLOR_KEY                     = 'com.apple.quicktime.opColor';

    /**
     * QuickTime metadata key exposing audio balance from smhd.
     */
    public const string BALANCE_KEY                      = 'com.apple.quicktime.balance';

    /**
     * QuickTime metadata key exposing looping style from udta LOOP atom.
     */
    public const string LOOP_KEY                         = 'com.apple.quicktime.loopStyle';

    /**
     * QuickTime metadata key exposing play-selection-only flag from udta SelO atom.
     */
    public const string PLAY_SELECTION_ONLY_KEY          = 'com.apple.quicktime.playSelectionOnly';

    /**
     * QuickTime metadata key exposing play-all-frames flag from udta AllF atom.
     */
    public const string PLAY_ALL_FRAMES_KEY              = 'com.apple.quicktime.playAllFrames';

    /**
     * QuickTime metadata key exposing default window location from udta WLOC atom.
     */
    public const string WINDOW_LOCATION_KEY              = 'com.apple.quicktime.windowLocation';

    /**
     * Mapping of shorthand lookup keys to canonical QuickTime metadata identifiers.
     *
     * @var array<string, list<string>>
     */
    private const array KEY_ALIASES                      = [
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
        self::DURATION_KEY                     => [self::DURATION_KEY, 'Duration'],
        'Duration'                             => ['Duration', self::DURATION_KEY],
        'VideoFrameRate'                       => ['VideoFrameRate', 'com.apple.quicktime.videoFrameRate'],
        'HDRFormat'                            => ['HDRFormat', 'com.apple.quicktime.hdrFormat'],
        'TransferFunction'                     => ['TransferFunction', 'com.apple.quicktime.transferFunction'],
        'ColorPrimaries'                       => ['ColorPrimaries', 'com.apple.quicktime.colorPrimaries'],
        'AudioBitsPerChannel'                  => ['AudioBitsPerChannel', self::AUDIO_BITS_PER_SAMPLE_KEY],
        self::TRACK_NAME_KEY                   => [self::TRACK_NAME_KEY, 'TrackName'],
        'TrackName'                            => ['TrackName', self::TRACK_NAME_KEY],
        self::CREATE_DATE_KEY                  => [self::CREATE_DATE_KEY, 'CreateDate'],
        'CreateDate'                           => ['CreateDate', self::CREATE_DATE_KEY],
        self::MODIFY_DATE_KEY                  => [self::MODIFY_DATE_KEY, 'ModifyDate'],
        'ModifyDate'                           => ['ModifyDate', self::MODIFY_DATE_KEY],
        self::TIME_SCALE_KEY                   => [self::TIME_SCALE_KEY, 'TimeScale'],
        'TimeScale'                            => ['TimeScale', self::TIME_SCALE_KEY],
        self::PREFERRED_RATE_KEY               => [self::PREFERRED_RATE_KEY, 'PreferredRate'],
        'PreferredRate'                        => ['PreferredRate', self::PREFERRED_RATE_KEY],
        self::PREFERRED_VOLUME_KEY             => [self::PREFERRED_VOLUME_KEY, 'PreferredVolume'],
        'PreferredVolume'                      => ['PreferredVolume', self::PREFERRED_VOLUME_KEY],
        self::MATRIX_STRUCTURE_KEY             => [self::MATRIX_STRUCTURE_KEY, 'MatrixStructure'],
        'MatrixStructure'                      => ['MatrixStructure', self::MATRIX_STRUCTURE_KEY],
        self::NEXT_TRACK_ID_KEY                => [self::NEXT_TRACK_ID_KEY, 'NextTrackID'],
        'NextTrackID'                          => ['NextTrackID', self::NEXT_TRACK_ID_KEY],
        self::PREVIEW_TIME_KEY                 => [self::PREVIEW_TIME_KEY, 'PreviewTime'],
        'PreviewTime'                          => ['PreviewTime', self::PREVIEW_TIME_KEY],
        self::PREVIEW_DURATION_KEY             => [self::PREVIEW_DURATION_KEY, 'PreviewDuration'],
        'PreviewDuration'                      => ['PreviewDuration', self::PREVIEW_DURATION_KEY],
        self::POSTER_TIME_KEY                  => [self::POSTER_TIME_KEY, 'PosterTime'],
        'PosterTime'                           => ['PosterTime', self::POSTER_TIME_KEY],
        self::SELECTION_TIME_KEY               => [self::SELECTION_TIME_KEY, 'SelectionTime'],
        'SelectionTime'                        => ['SelectionTime', self::SELECTION_TIME_KEY],
        self::SELECTION_DURATION_KEY           => [self::SELECTION_DURATION_KEY, 'SelectionDuration'],
        'SelectionDuration'                    => ['SelectionDuration', self::SELECTION_DURATION_KEY],
        self::CURRENT_TIME_KEY                 => [self::CURRENT_TIME_KEY, 'CurrentTime'],
        'CurrentTime'                          => ['CurrentTime', self::CURRENT_TIME_KEY],
        self::TRACK_CREATE_DATE_KEY            => [self::TRACK_CREATE_DATE_KEY, 'TrackCreateDate'],
        'TrackCreateDate'                      => ['TrackCreateDate', self::TRACK_CREATE_DATE_KEY],
        self::TRACK_MODIFY_DATE_KEY            => [self::TRACK_MODIFY_DATE_KEY, 'TrackModifyDate'],
        'TrackModifyDate'                      => ['TrackModifyDate', self::TRACK_MODIFY_DATE_KEY],
        self::TRACK_ID_KEY                     => [self::TRACK_ID_KEY, 'TrackID'],
        'TrackID'                              => ['TrackID', self::TRACK_ID_KEY],
        self::TRACK_DURATION_KEY               => [self::TRACK_DURATION_KEY, 'TrackDuration'],
        'TrackDuration'                        => ['TrackDuration', self::TRACK_DURATION_KEY],
        self::TRACK_LAYER_KEY                  => [self::TRACK_LAYER_KEY, 'TrackLayer'],
        'TrackLayer'                           => ['TrackLayer', self::TRACK_LAYER_KEY],
        self::TRACK_VOLUME_KEY                 => [self::TRACK_VOLUME_KEY, 'TrackVolume'],
        'TrackVolume'                          => ['TrackVolume', self::TRACK_VOLUME_KEY],
        self::ROTATION_KEY                     => [self::ROTATION_KEY, 'Rotation'],
        'Rotation'                             => ['Rotation', self::ROTATION_KEY],
        self::TRACK_MATRIX_KEY                 => [self::TRACK_MATRIX_KEY, 'TrackMatrixStructure'],
        'TrackMatrixStructure'                 => ['TrackMatrixStructure', self::TRACK_MATRIX_KEY],
        self::MEDIA_CREATE_DATE_KEY            => [self::MEDIA_CREATE_DATE_KEY, 'MediaCreateDate'],
        'MediaCreateDate'                      => ['MediaCreateDate', self::MEDIA_CREATE_DATE_KEY],
        self::MEDIA_MODIFY_DATE_KEY            => [self::MEDIA_MODIFY_DATE_KEY, 'MediaModifyDate'],
        'MediaModifyDate'                      => ['MediaModifyDate', self::MEDIA_MODIFY_DATE_KEY],
        self::MEDIA_DURATION_KEY               => [self::MEDIA_DURATION_KEY, 'MediaDuration'],
        'MediaDuration'                        => ['MediaDuration', self::MEDIA_DURATION_KEY],
        self::MEDIA_TIME_SCALE_KEY             => [self::MEDIA_TIME_SCALE_KEY, 'MediaTimeScale'],
        'MediaTimeScale'                       => ['MediaTimeScale', self::MEDIA_TIME_SCALE_KEY],
        self::MEDIA_LANGUAGE_CODE_KEY          => [self::MEDIA_LANGUAGE_CODE_KEY, 'MediaLanguageCode'],
        'MediaLanguageCode'                    => ['MediaLanguageCode', self::MEDIA_LANGUAGE_CODE_KEY],
        self::VIDEO_BIT_DEPTH_KEY              => [self::VIDEO_BIT_DEPTH_KEY, 'BitDepth', 'VideoBitDepth'],
        'BitDepth'                             => ['BitDepth', self::VIDEO_BIT_DEPTH_KEY, 'VideoBitDepth'],
        'VideoBitDepth'                        => ['VideoBitDepth', self::VIDEO_BIT_DEPTH_KEY, 'BitDepth'],
        self::GRAPHICS_MODE_KEY                => [self::GRAPHICS_MODE_KEY, 'GraphicsMode'],
        'GraphicsMode'                         => ['GraphicsMode', self::GRAPHICS_MODE_KEY],
        self::OP_COLOR_KEY                     => [self::OP_COLOR_KEY, 'OpColor'],
        'OpColor'                              => ['OpColor', self::OP_COLOR_KEY],
        self::BALANCE_KEY                      => [self::BALANCE_KEY, 'Balance'],
        'Balance'                              => ['Balance', self::BALANCE_KEY],
        self::LOOP_KEY                         => [self::LOOP_KEY, 'LoopStyle'],
        'LoopStyle'                            => ['LoopStyle', self::LOOP_KEY],
        self::PLAY_SELECTION_ONLY_KEY          => [self::PLAY_SELECTION_ONLY_KEY, 'PlaySelectionOnly'],
        'PlaySelectionOnly'                    => ['PlaySelectionOnly', self::PLAY_SELECTION_ONLY_KEY],
        self::PLAY_ALL_FRAMES_KEY              => [self::PLAY_ALL_FRAMES_KEY, 'PlayAllFrames'],
        'PlayAllFrames'                        => ['PlayAllFrames', self::PLAY_ALL_FRAMES_KEY],
        self::WINDOW_LOCATION_KEY              => [self::WINDOW_LOCATION_KEY, 'WindowLocation'],
        'WindowLocation'                       => ['WindowLocation', self::WINDOW_LOCATION_KEY],
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
        $candidates  = self::KEY_ALIASES[$key] ?? [$key];

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
        $candidates  = self::KEY_ALIASES[$key] ?? [$key];

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
