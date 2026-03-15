<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use Closure;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function array_key_exists;
use function gmdate;
use function in_array;
use function is_finite;
use function mb_check_encoding;
use function ord;
use function round;
use function rtrim;
use function sprintf;
use function substr;

/**
 * Parses track and media boxes within ISO BMFF containers, extracting
 * codec, resolution, and audio/video metadata from sample descriptions.
 *
 * ISO/IEC 14496-12 §8.4 defines the track structure and §8.5 the media boxes.
 *
 * @phpstan-type QuickTimeKeyMap    = array<string, string|int|float|bool>
 * @phpstan-type SampleEntryMap     = array<string, int|float|string|bool>
 */
final readonly class TrackMediaParser
{
    /**
     * Track-header flag indicating whether a track is enabled.
     */
    private const int TKHD_FLAG_TRACK_ENABLED = 0x000001;

    /**
     * Track-header flag indicating whether a track participates in movie presentation.
     */
    private const int TKHD_FLAG_TRACK_IN_MOVIE = 0x000002;

    /**
     * Seconds between the Mac OS epoch (1904-01-01 00:00:00 UTC) and
     * the Unix epoch (1970-01-01 00:00:00 UTC).
     *
     * ISO/IEC 14496-12 §8.2.2: creation_time and modification_time are
     * measured in seconds since Jan 1, 1904.
     */
    private const int MAC_EPOCH_OFFSET = 2_082_844_800;

    /**
     * Divisor for decoding ISO 14496-12 16.16 fixed-point rate values.
     *
     * The integer part occupies the upper 16 bits, so dividing by 65536
     * yields the floating-point equivalent.
     */
    private const float FIXED_POINT_16_16_DIVISOR = 65536.0;

    /**
     * Divisor for decoding ISO 14496-12 8.8 fixed-point volume values.
     *
     * The integer part occupies the upper 8 bits, so dividing by 256
     * yields the floating-point equivalent.
     */
    private const float FIXED_POINT_8_8_DIVISOR = 256.0;

    /**
     * Maximum number of entries accepted from an stts (TimeToSampleBox).
     *
     * Prevents resource exhaustion from artificially large entry counts.
     * ISO/IEC 14496-12 §8.6.1.
     */
    private const int MAX_STTS_ENTRIES = 16_384;

    private VideoSampleEntryParser $videoParser;

    private AudioSampleEntryParser $audioParser;

    /**
     * @param BoxNavigator                                             $boxNavigator   Shared box navigation infrastructure.
     * @param Closure(BoxDescriptor, IsoBmffParseContext): void        $processUdtaBox Callback for processing udta boxes.
     * @param Closure(BoxDescriptor): array<int, IsoBmffDataReference> $validateDinf   Callback for validating dinf boxes.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
        private Closure $processUdtaBox,
        private Closure $validateDinf,
    ) {
        $this->videoParser = new VideoSampleEntryParser();
        $this->audioParser = new AudioSampleEntryParser();
    }

    /**
     * Parses a track box and returns selectable metadata extracted from it.
     *
     * @param BoxDescriptor       $trak    Box descriptor for the track container.
     * @param IsoBmffParseContext $context Shared parse-state context.
     *
     * @return array{handler:?string, isEnabledInMovie:bool, keys:QuickTimeKeyMap}
     */
    public function parseTrak(BoxDescriptor $trak, IsoBmffParseContext $context): array
    {
        $tkhdWidth        = null;
        $tkhdHeight       = null;
        $handler          = null;
        $handlerName      = null;
        $sampleInfo       = [];
        $isEnabledInMovie = false;
        $tkhdCount        = 0;
        $mdiaCount        = 0;
        $udtaCount        = 0;

        foreach ($this->boxNavigator->walkChildren($trak) as $child) {
            if ($child->type === BoxType::TKHD->value) {
                ++$tkhdCount;

                if ($tkhdCount > 1) {
                    throw new ParseError('trak must contain exactly one tkhd box', 1376);
                }

                [$tkhdWidth, $tkhdHeight, $isEnabledInMovie] = $this->parseTkhd($child);
            } elseif ($child->type === BoxType::MDIA->value) {
                ++$mdiaCount;

                if ($mdiaCount > 1) {
                    throw new ParseError('trak must contain exactly one mdia box', 1377);
                }

                [$handler, $handlerName, $sampleInfo] = $this->parseMdia($child, $context);
            } elseif ($child->type === BoxType::UDTA->value) {
                ++$udtaCount;

                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in trak', 1912);
                }

                ($this->processUdtaBox)($child, $context);
            }
        }

        if ($tkhdCount === 0) {
            throw new ParseError('trak must contain exactly one tkhd box', 1891);
        }

        if ($mdiaCount === 0) {
            throw new ParseError('trak must contain exactly one mdia box', 1892);
        }

        if ($handler === null) {
            return [
                'handler'          => null,
                'isEnabledInMovie' => $isEnabledInMovie,
                'keys'             => [],
            ];
        }

        if (($handlerName !== null) && ($handlerName !== '') && !array_key_exists(QuickTimeMeta::HANDLER_DESCRIPTION_KEY, $context->qtKeys)) {
            $context->qtKeys[QuickTimeMeta::HANDLER_DESCRIPTION_KEY] = $handlerName;
        }

        $trackKeys = match ($handler) {
            'vide'  => $this->buildVideoTrackKeys($sampleInfo, $tkhdWidth, $tkhdHeight),
            'soun'  => $this->buildAudioTrackKeys($sampleInfo),
            'meta'  => $this->buildMetaTrackKeys($sampleInfo, $context),
            default => [],
        };

        return [
            'handler'          => $handler,
            'isEnabledInMovie' => $isEnabledInMovie,
            'keys'             => $trackKeys,
        ];
    }

    /**
     * Builds QuickTime keys for video tracks, preserving sample-over-tkhd precedence.
     *
     * @param SampleEntryMap $sampleInfo
     *
     * @return QuickTimeKeyMap
     */
    private function buildVideoTrackKeys(array $sampleInfo, ?int $tkhdWidth, ?int $tkhdHeight): array
    {
        $width  = $sampleInfo['width'] ?? $tkhdWidth;
        $height = $sampleInfo['height'] ?? $tkhdHeight;

        /** @var QuickTimeKeyMap $trackKeys */
        $trackKeys = [];

        if (($width !== null) && ($width > 0)) {
            $trackKeys[QuickTimeMeta::VIDEO_WIDTH_KEY] = $width;
        }

        if (($height !== null) && ($height > 0)) {
            $trackKeys[QuickTimeMeta::VIDEO_HEIGHT_KEY] = $height;
        }

        if (isset($sampleInfo['format']) && ($sampleInfo['format'] !== '')) {
            $trackKeys[QuickTimeMeta::VIDEO_CODEC_KEY] = $sampleInfo['format'];
        }

        if (isset($sampleInfo['compressorName']) && ($sampleInfo['compressorName'] !== '')) {
            $trackKeys[QuickTimeMeta::COMPRESSOR_NAME_KEY] = $sampleInfo['compressorName'];
        }

        if (isset($sampleInfo['horizontalResolution'])) {
            $trackKeys[QuickTimeMeta::VIDEO_HORIZONTAL_RESOLUTION_KEY] = $sampleInfo['horizontalResolution'];
        }

        if (isset($sampleInfo['verticalResolution'])) {
            $trackKeys[QuickTimeMeta::VIDEO_VERTICAL_RESOLUTION_KEY] = $sampleInfo['verticalResolution'];
        }

        if (isset($sampleInfo['frameCount']) && ($sampleInfo['frameCount'] !== 1)) {
            $trackKeys[QuickTimeMeta::VIDEO_FRAME_COUNT_KEY] = $sampleInfo['frameCount'];
        }

        if (isset($sampleInfo['frameRate'])) {
            $trackKeys[QuickTimeMeta::VIDEO_FRAME_RATE_KEY] = $sampleInfo['frameRate'];
        }

        if (isset($sampleInfo['maxBitrate'])) {
            $trackKeys[QuickTimeMeta::VIDEO_MAX_BITRATE_KEY] = $sampleInfo['maxBitrate'];
        }

        if (isset($sampleInfo['avgBitrate'])) {
            $trackKeys[QuickTimeMeta::VIDEO_AVG_BITRATE_KEY] = $sampleInfo['avgBitrate'];
        }

        if (isset($sampleInfo['colorPrimaries'])) {
            $trackKeys[QuickTimeMeta::COLOR_PRIMARIES_KEY] = $sampleInfo['colorPrimaries'];
        }

        if (isset($sampleInfo['transferCharacteristics'])) {
            $trackKeys[QuickTimeMeta::TRANSFER_CHARACTERISTICS_KEY] = $sampleInfo['transferCharacteristics'];
        }

        if (isset($sampleInfo['matrixCoefficients'])) {
            $trackKeys[QuickTimeMeta::MATRIX_COEFFICIENTS_KEY] = $sampleInfo['matrixCoefficients'];
        }

        if (isset($sampleInfo['fullRangeFlag'])) {
            $trackKeys[QuickTimeMeta::VIDEO_FULL_RANGE_FLAG_KEY] = $sampleInfo['fullRangeFlag'];
        }

        return $trackKeys;
    }

    /**
     * Builds QuickTime keys for audio tracks.
     *
     * @param SampleEntryMap $sampleInfo
     *
     * @return QuickTimeKeyMap
     */
    private function buildAudioTrackKeys(array $sampleInfo): array
    {
        /** @var QuickTimeKeyMap $trackKeys */
        $trackKeys = [];

        if (isset($sampleInfo['format']) && ($sampleInfo['format'] !== '')) {
            $trackKeys[QuickTimeMeta::AUDIO_FORMAT_KEY] = $sampleInfo['format'];
            $trackKeys[QuickTimeMeta::AUDIO_CODEC_KEY]  = $sampleInfo['format'];
        }

        if (isset($sampleInfo['channels'])) {
            $trackKeys[QuickTimeMeta::AUDIO_CHANNELS_KEY] = $sampleInfo['channels'];
        }

        if (isset($sampleInfo['bitsPerSample'])) {
            $trackKeys[QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY] = $sampleInfo['bitsPerSample'];
        }

        if (isset($sampleInfo['sampleRate'])) {
            $trackKeys[QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY] = $sampleInfo['sampleRate'];
        }

        if (isset($sampleInfo['maxBitrate'])) {
            $trackKeys[QuickTimeMeta::AUDIO_MAX_BITRATE_KEY] = $sampleInfo['maxBitrate'];
        }

        if (isset($sampleInfo['avgBitrate'])) {
            $trackKeys[QuickTimeMeta::AUDIO_AVG_BITRATE_KEY] = $sampleInfo['avgBitrate'];
        }

        $this->copyLpcmSampleInfoKeys($sampleInfo, $trackKeys);

        return $trackKeys;
    }

    /**
     * Copies LPCM-derived sample-info keys into the track key map.
     *
     * @param SampleEntryMap  $sampleInfo
     * @param QuickTimeKeyMap $trackKeys
     */
    private function copyLpcmSampleInfoKeys(array $sampleInfo, array &$trackKeys): void
    {
        $lpcmKeys = [
            QuickTimeMeta::AUDIO_LPCM_FORMAT_FLAGS_KEY,
            QuickTimeMeta::AUDIO_LPCM_NUMERIC_FORMAT_KEY,
            QuickTimeMeta::AUDIO_LPCM_ENDIANNESS_KEY,
            QuickTimeMeta::AUDIO_LPCM_PACKING_KEY,
            QuickTimeMeta::AUDIO_LPCM_IS_FLOAT_KEY,
            QuickTimeMeta::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY,
            QuickTimeMeta::AUDIO_LPCM_IS_BIG_ENDIAN_KEY,
            QuickTimeMeta::AUDIO_LPCM_IS_PACKED_KEY,
            QuickTimeMeta::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY,
            QuickTimeMeta::AUDIO_LPCM_BYTES_PER_PACKET_KEY,
            QuickTimeMeta::AUDIO_LPCM_FRAMES_PER_PACKET_KEY,
        ];

        foreach ($lpcmKeys as $lpcmKey) {
            if (array_key_exists($lpcmKey, $sampleInfo)) {
                $trackKeys[$lpcmKey] = $sampleInfo[$lpcmKey];
            }
        }
    }

    /**
     * Builds QuickTime keys for metadata handler tracks (e.g. DJI telemetry).
     *
     * Captures the sample-entry format code as the MetaFormat key and stores
     * it directly in the shared context when not already set.
     *
     * @param SampleEntryMap     $sampleInfo
     * @param IsoBmffParseContext $context
     *
     * @return QuickTimeKeyMap Always empty — context is updated directly.
     */
    private function buildMetaTrackKeys(array $sampleInfo, IsoBmffParseContext $context): array
    {
        if (!isset($sampleInfo['metaFormat'])) {
            return [];
        }

        $format = $sampleInfo['metaFormat'];

        if (($format !== '') && !array_key_exists(QuickTimeMeta::META_FORMAT_KEY, $context->qtKeys)) {
            $context->qtKeys[QuickTimeMeta::META_FORMAT_KEY] = $format;
        }

        return [];
    }

    /**
     * Parses the movie header box (`mvhd`) and returns extracted container metadata.
     *
     * ISO/IEC 14496-12 §8.2.2: the mvhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale and next_track_ID fields
     * must be non-zero.
     *
     * @param BoxDescriptor $mvhd Movie header box descriptor.
     *
     * @return QuickTimeKeyMap Extracted mvhd fields (CreateDate, ModifyDate, Duration, etc.).
     */
    public function parseMvhd(BoxDescriptor $mvhd): array
    {
        $header    = $this->parseTimescaleHeader($mvhd, 100, 112, 1906, 1908, 1407, 1907, 1408);
        $version   = $header['version'];
        $timescale = $header['timescale'];

        $win = $mvhd->window;

        // ISO/IEC 14496-12 §8.2.2: duration in timescale units
        if ($version === 1) {
            $durationUnits = $win->readU64BE()->toInt('mvhd duration');
        } else {
            $durationUnits = $win->readU32BE();
        }

        // rate: 16.16 fixed-point, default 0x00010000 = 1.0 (normal playback)
        $rateRaw = $win->readU32BE();
        // volume: 8.8 fixed-point, default 0x0100 = 1.0 (full volume)
        $volumeRaw = $win->readU16BE();

        // Skip reserved(2) + reserved(8) + matrix(36) + pre_defined(24) = 70 bytes
        $win->read(70);

        $nextTrackId = $win->readU32BE();

        if ($nextTrackId === 0) {
            throw new ParseError('mvhd next_track_ID must not be zero', 1409);
        }

        /** @var QuickTimeKeyMap $keys */
        $keys = [];

        if ($header['creationTime'] > 0) {
            $keys[QuickTimeMeta::CREATE_DATE_KEY] = $this->formatMacTimestamp($header['creationTime']);
        }

        if ($header['modificationTime'] > 0) {
            $keys[QuickTimeMeta::MODIFY_DATE_KEY] = $this->formatMacTimestamp($header['modificationTime']);
        }

        $keys[QuickTimeMeta::TIME_SCALE_KEY] = $timescale;

        if (($durationUnits > 0) && ($timescale > 0)) {
            $keys[QuickTimeMeta::DURATION_KEY] = (float) $durationUnits / $timescale;
        }

        if ($rateRaw > 0) {
            $keys[QuickTimeMeta::PREFERRED_RATE_KEY] = $rateRaw === 0x00010000 ? 1.0 : ($rateRaw / self::FIXED_POINT_16_16_DIVISOR);
        }

        if ($volumeRaw > 0) {
            $keys[QuickTimeMeta::PREFERRED_VOLUME_KEY] = $volumeRaw === 0x0100 ? 1.0 : ($volumeRaw / self::FIXED_POINT_8_8_DIVISOR);
        }

        return $keys;
    }

    /**
     * Parses the handler reference box (`hdlr`).
     *
     * @param BoxDescriptor $hdlr Handler box descriptor.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseHdlr(BoxDescriptor $hdlr): array
    {
        $win = $hdlr->window;
        $win->seek(0);

        if ($hdlr->contentSize < 24) {
            throw new ParseError('hdlr box truncated', 1147);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            throw new ParseError('unsupported hdlr box version', 1148);
        }

        if ($header->flags !== 0) {
            throw new ParseError('unsupported hdlr box flags', 1149);
        }

        // pre_defined (ISO) / component type (QuickTime) — skip without validating;
        // MOV files commonly write 'mhlr' or 'dhlr' here.
        $win->readU32BE();

        $handler = $win->read(4);
        $win->read(12);

        // Postel's Law: ISO 14496-12 §8.4.3.1 requires these 12 bytes to be zero,
        // but Apple QuickTime historically used them for component manufacturer,
        // component type, and component flags.  Many real-world MOV files have
        // non-zero values here.  Tolerate silently — the fields are unused for
        // metadata extraction.

        $handlerType = $this->boxNavigator->normalizeFourcc($handler);
        $remaining   = $hdlr->contentSize - $win->tell();
        $name        = null;

        if ($remaining > 0) {
            $nameBytes  = $win->read($remaining);
            $countedLen = ord($nameBytes[0]);

            if ($countedLen <= $remaining - 1) {
                // QuickTime File Format 2012, "Handler Reference Atom" (p. 85):
                // component name is a counted string (first byte = length).
                $name = $countedLen > 0 ? substr($nameBytes, 1, $countedLen) : null;
            } elseif ($nameBytes[$remaining - 1] === "\0") {
                // ISO/IEC 14496-12 §8.4.3: NUL-terminated UTF-8 handler name.
                $trimmed = rtrim($nameBytes, "\0");

                if (($trimmed !== '') && !mb_check_encoding($trimmed, 'UTF-8')) {
                    throw new ParseError('hdlr handler name contains invalid UTF-8', 1384);
                }

                $name = $trimmed !== '' ? $trimmed : null;
            } else {
                // Best-effort: strip NUL bytes from raw name when neither format matches
                $trimmed = rtrim($nameBytes, "\0");
                $name    = $trimmed !== '' ? $trimmed : null;
            }
        }

        return [$handlerType === '' ? null : $handlerType, $name];
    }

    /**
     * Parses the track header box (`tkhd`) and extracts display width/height.
     *
     * @param BoxDescriptor $tkhd Track header descriptor.
     *
     * @return array{0: ?int, 1: ?int, 2: bool}
     */
    private function parseTkhd(BoxDescriptor $tkhd): array
    {
        $win = $tkhd->window;
        $win->seek(0);

        if ($tkhd->contentSize < 84) {
            throw new ParseError('tkhd box truncated', 1144);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if (($header->version !== 0) && ($header->version !== 1)) {
            throw new ParseError('unsupported tkhd box version', 1145);
        }

        $version = $header->version;
        $flags   = $header->flags;

        // ISO/IEC 14496-12 §8.3.2: version 0 uses 32-bit timestamps, version 1 uses 64-bit
        if ($version === 1) {
            if ($tkhd->contentSize < 96) {
                throw new ParseError('tkhd version 1 box truncated', 1146);
            }

            $win->read(8 + 8); // creation(64), modification(64)
            $trackId = $win->readU32BE();
            $win->read(4); // reserved
            $win->read(8); // duration(64)
        } else {
            $win->read(4 + 4); // creation(32), modification(32)
            $trackId = $win->readU32BE();
            $win->read(4); // reserved
            $win->read(4); // duration(32)
        }

        // ISO/IEC 14496-12 §8.3.2: track_ID must be non-zero
        if ($trackId === 0) {
            throw new ParseError('tkhd track_ID must not be zero', 1369);
        }

        $win->read(8); // reserved (64-bit)

        $win->read(2); // layer
        $win->read(2); // alternate group
        $win->read(2); // volume

        $win->read(2); // reserved (16-bit)

        $win->read(36); // matrix

        $widthFixed  = $win->readU32BE();
        $heightFixed = $win->readU32BE();

        // ISO/IEC 14496-12 §8.3.2: when track_size_is_aspect_ratio flag is set,
        // width/height represent aspect ratio, not pixel dimensions
        $isAspectRatio = ($flags & 0x000008) !== 0;

        // Decode 16.16 fixed-point with rounding instead of truncation
        $width  = ($widthFixed > 0 && !$isAspectRatio) ? (int) round($widthFixed / 65536) : null;
        $height = ($heightFixed > 0 && !$isAspectRatio) ? (int) round($heightFixed / 65536) : null;

        // ISO/IEC 14496-12 §8.3.2: usable movie tracks are enabled and marked in_movie.
        $isTrackEnabled   = ($flags & self::TKHD_FLAG_TRACK_ENABLED) !== 0;
        $isTrackInMovie   = ($flags & self::TKHD_FLAG_TRACK_IN_MOVIE) !== 0;
        $isEnabledInMovie = $isTrackEnabled && $isTrackInMovie;

        return [$width, $height, $isEnabledInMovie];
    }

    /**
     * Parses a versioned header with creation/modification timestamps and timescale.
     *
     * Shared structure between mvhd (ISO 14496-12 §8.2.2) and mdhd (§8.4.2).
     * After this call the box window is positioned immediately after the timescale field.
     *
     * @param BoxDescriptor $box           Box descriptor to parse.
     * @param int           $v0MinPayload  Minimum payload for version 0.
     * @param int           $v1MinPayload  Minimum payload for version 1.
     * @param int           $truncatedCode Error code for initial truncation check.
     * @param int           $versionCode   Error code for unsupported version.
     * @param int           $flagsCode     Error code for unsupported flags.
     * @param int           $payloadCode   Error code for version-specific truncation.
     * @param int           $timescaleCode Error code for zero timescale.
     *
     * @return array{version:int, creationTime:int, modificationTime:int, timescale:int}
     */
    private function parseTimescaleHeader(
        BoxDescriptor $box,
        int $v0MinPayload,
        int $v1MinPayload,
        int $truncatedCode,
        int $versionCode,
        int $flagsCode,
        int $payloadCode,
        int $timescaleCode,
    ): array {
        $win = $box->window;
        $win->seek(0);

        if ($box->contentSize < 4) {
            throw new ParseError('box truncated', $truncatedCode);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if (($header->version !== 0) && ($header->version !== 1)) {
            throw new ParseError('unsupported box version', $versionCode);
        }

        if ($header->flags !== 0) {
            throw new ParseError('unsupported box flags', $flagsCode);
        }

        $version    = $header->version;
        $minPayload = $version === 1 ? $v1MinPayload : $v0MinPayload;

        if ($box->contentSize < $minPayload) {
            throw new ParseError('box truncated', $payloadCode);
        }

        if ($version === 1) {
            $creationTime     = $win->readU64BE()->toInt('creation_time');
            $modificationTime = $win->readU64BE()->toInt('modification_time');
        } else {
            $creationTime     = $win->readU32BE();
            $modificationTime = $win->readU32BE();
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('timescale must not be zero', $timescaleCode);
        }

        return [
            'version'          => $version,
            'creationTime'     => $creationTime,
            'modificationTime' => $modificationTime,
            'timescale'        => $timescale,
        ];
    }

    /**
     * Parses the media header box (`mdhd`) and returns the media timescale.
     *
     * ISO/IEC 14496-12 §8.4.2: the mdhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale field must be non-zero.
     *
     * @param BoxDescriptor $mdhd Media header box descriptor.
     *
     * @return int Media timescale in units per second.
     */
    private function parseMdhd(BoxDescriptor $mdhd): int
    {
        $header = $this->parseTimescaleHeader($mdhd, 24, 36, 1901, 1903, 1904, 1902, 1905);

        return $header['timescale'];
    }

    /**
     * Parses the media box (`mdia`) and returns handler/sample information.
     *
     * QuickTime File Format (2012), "User Data Atoms": udta may appear as a
     * child of mdia in addition to moov and trak. Media-level user data is
     * parsed with the same strategy as other udta paths.
     *
     * @param BoxDescriptor       $mdia    Media box descriptor.
     * @param IsoBmffParseContext $context Shared parse-state context.
     *
     * @return array{0: ?string, 1: ?string, 2: SampleEntryMap}
     */
    private function parseMdia(BoxDescriptor $mdia, IsoBmffParseContext $context): array
    {
        $handler        = null;
        $handlerName    = null;
        $sampleInfo     = [];
        $hdlrCount      = 0;
        $minfCount      = 0;
        $mdhdCount      = 0;
        $udtaCount      = 0;
        $mediaTimescale = 0;

        // Collect children first so hdlr/minf order does not matter
        $children = [];

        foreach ($this->boxNavigator->walkChildren($mdia) as $child) {
            if ($child->type === BoxType::HDLR->value) {
                ++$hdlrCount;

                if ($hdlrCount > 1) {
                    throw new ParseError('mdia must contain exactly one hdlr box', 1378);
                }

                [$handler, $handlerName] = $this->parseHdlr($child);
            } elseif ($child->type === BoxType::MINF->value) {
                ++$minfCount;

                if ($minfCount > 1) {
                    throw new ParseError('mdia must contain exactly one minf box', 1379);
                }

                $children[] = $child;
            } elseif ($child->type === BoxType::MDHD->value) {
                ++$mdhdCount;

                if ($mdhdCount > 1) {
                    throw new ParseError('mdia must contain exactly one mdhd box', 1380);
                }

                $mediaTimescale = $this->parseMdhd($child);
            } elseif ($child->type === BoxType::UDTA->value) {
                ++$udtaCount;

                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in mdia', 1990);
                }

                ($this->processUdtaBox)($child, $context);
            }
        }

        if ($hdlrCount === 0) {
            throw new ParseError('mdia must contain exactly one hdlr box', 1893);
        }

        if ($minfCount === 0) {
            throw new ParseError('mdia must contain exactly one minf box', 1894);
        }

        if ($mdhdCount === 0) {
            throw new ParseError('mdia must contain exactly one mdhd box', 1895);
        }

        // Parse minf after hdlr so handler type is always available
        foreach ($children as $child) {
            $sampleInfo = $this->parseMinf($child, $handler, $mediaTimescale);
        }

        return [$handler, $handlerName, $sampleInfo];
    }

    /**
     * Parses the media information box (`minf`) to find sample table details.
     *
     * @param BoxDescriptor $minf           Media information descriptor.
     * @param string|null   $handlerType    Declared handler type for the media.
     * @param int           $mediaTimescale Media timescale from mdhd, used for frame-rate computation.
     *
     * @return SampleEntryMap
     */
    private function parseMinf(BoxDescriptor $minf, ?string $handlerType, int $mediaTimescale = 0): array
    {
        if ($handlerType === null) {
            return [];
        }

        $stblCount    = 0;
        $dinfCount    = 0;
        $mediaHdrType = null;
        $result       = [];

        // Determine expected media header box from handler type
        $expectedMediaHdr = match ($handlerType) {
            'vide'  => BoxType::VMHD->value,
            'soun'  => BoxType::SMHD->value,
            default => BoxType::NMHD->value,
        };

        foreach ($this->boxNavigator->walkChildren($minf) as $child) {
            if ($child->type === BoxType::STBL->value) {
                ++$stblCount;

                if ($stblCount > 1) {
                    throw new ParseError('minf must contain exactly one stbl box', 1381);
                }

                $result = $this->parseStbl($child, $handlerType, $mediaTimescale);
            } elseif ($child->type === BoxType::DINF->value) {
                ++$dinfCount;

                if ($dinfCount > 1) {
                    throw new ParseError('minf must contain exactly one dinf box', 1382);
                }

                // Return value intentionally discarded: dinf validation only in track context
                ($this->validateDinf)($child);
            } elseif (in_array($child->type, [BoxType::VMHD->value, BoxType::SMHD->value, BoxType::NMHD->value], true)) {
                // Enforce exactly one handler-matching media header
                if ($mediaHdrType !== null) {
                    throw new ParseError('minf must contain exactly one media header box', 1421);
                }

                $mediaHdrType = $child->type;
            }
        }

        if ($stblCount === 0) {
            throw new ParseError('minf must contain exactly one stbl box', 1896);
        }

        if ($dinfCount === 0) {
            throw new ParseError('minf must contain exactly one dinf box', 1897);
        }

        // Validate media header presence and handler match
        if ($mediaHdrType === null) {
            // QuickTime metadata tracks may use gmhd (or omit nmhd entirely)
            // while still providing parseable sample tables in minf/stbl.
            if ($handlerType === 'meta') {
                return $result;
            }

            throw new ParseError(sprintf('minf missing required media header box %s for handler %s', $expectedMediaHdr, $handlerType), 1422);
        }

        return $result;
    }

    /**
     * Parses the sample table box (`stbl`).
     *
     * @param BoxDescriptor $stbl           Sample table descriptor.
     * @param string        $handlerType    Media handler type.
     * @param int           $mediaTimescale Media timescale for frame-rate computation (0 = skip).
     *
     * @return SampleEntryMap
     */
    private function parseStbl(BoxDescriptor $stbl, string $handlerType, int $mediaTimescale = 0): array
    {
        $stsdCount      = 0;
        $sttsCount      = 0;
        $stscCount      = 0;
        $stszCount      = 0;
        $stcoCount      = 0;
        $result         = [];
        $sttsDescriptor = null;

        foreach ($this->boxNavigator->walkChildren($stbl) as $child) {
            if ($child->type === BoxType::STSD->value) {
                ++$stsdCount;

                if ($stsdCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsd box', 1383);
                }

                $result = $this->parseStsd($child, $handlerType);
            } elseif ($child->type === BoxType::STTS->value) {
                ++$sttsCount;

                if ($sttsCount > 1) {
                    throw new ParseError('stbl must contain exactly one stts box', 1424);
                }

                $sttsDescriptor = $child;
            } elseif ($child->type === BoxType::STSC->value) {
                ++$stscCount;

                if ($stscCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsc box', 1425);
                }
            } elseif ($child->type === BoxType::STSZ->value || $child->type === BoxType::STZ2->value) {
                ++$stszCount;

                if ($stszCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsz or stz2 box', 1426);
                }
            } elseif ($child->type === BoxType::STCO->value || $child->type === BoxType::CO64->value) {
                ++$stcoCount;

                if ($stcoCount > 1) {
                    throw new ParseError('stbl must contain exactly one stco or co64 box', 1427);
                }
            }
        }

        if ($stsdCount === 0) {
            throw new ParseError('stbl must contain exactly one stsd box', 1898);
        }

        // Enforce mandatory core sample-table boxes
        if ($sttsCount === 0) {
            throw new ParseError('stbl must contain exactly one stts box', 1914);
        }

        if ($stscCount === 0) {
            throw new ParseError('stbl must contain exactly one stsc box', 1915);
        }

        if ($stszCount === 0) {
            throw new ParseError('stbl must contain exactly one stsz or stz2 box', 1916);
        }

        if ($stcoCount === 0) {
            throw new ParseError('stbl must contain exactly one stco or co64 box', 1917);
        }

        // ISO/IEC 14496-12 §8.6.1: compute video frame rate from stts when media timescale is available
        if (($handlerType === 'vide') && ($sttsDescriptor !== null) && ($mediaTimescale > 0)) {
            $frameRate = $this->computeFrameRateFromStts($sttsDescriptor, $mediaTimescale);

            if ($frameRate !== null) {
                $result['frameRate'] = $frameRate;
            }
        }

        return $result;
    }

    /**
     * Parses the sample description box (`stsd`).
     *
     * @param BoxDescriptor $stsd        Sample description descriptor.
     * @param string        $handlerType Handler type describing the media kind.
     *
     * @return SampleEntryMap
     */
    private function parseStsd(BoxDescriptor $stsd, string $handlerType): array
    {
        $win = $stsd->window;
        $win->seek(0);

        if ($stsd->contentSize < 8) {
            throw new ParseError('stsd box truncated', 1153);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        // ISO/IEC 14496-12 §8.5.2.2/§8.5.2.3: stsd is a FullBox with flags=0.
        // Version 1 is only valid in audio sample-description context.
        if (($header->version !== 0) && ($header->version !== 1)) {
            throw new ParseError('unsupported stsd box version', 1154);
        }

        if ($header->flags !== 0) {
            throw new ParseError('unsupported stsd box flags', 1155);
        }

        if (($header->version === 1) && ($handlerType !== 'soun')) {
            throw new ParseError('stsd version 1 requires audio handler context', 1925);
        }

        $version    = $header->version;
        $entryCount = $win->readU32BE();

        // ISO/IEC 14496-12 §8.5.2: Sample Description Box must contain at least one entry.
        if ($entryCount === 0) {
            throw new ParseError('stsd entry count must be at least 1', 1926);
        }

        if ($entryCount > ParserLimits::MAX_STSD_ENTRIES) {
            throw new ParseError('stsd entry count exceeds maximum allowed', 1156);
        }

        $result = [];
        $pos    = $win->tell();

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($pos + 8 > $stsd->contentSize) {
                throw new ParseError('stsd entry truncated', 1157);
            }

            $win->seek($pos);
            $entrySize = $win->readU32BE();
            $format    = $win->read(4);

            if (($entrySize < 16) || (($pos + $entrySize) > $stsd->contentSize)) {
                throw new ParseError('invalid stsd entry size', 1158);
            }

            $entryStart = $win->tell();
            $entryEnd   = $pos + $entrySize;

            // ISO 14496-12 §8.5.2.2: reserved 6-byte field (ignored for tolerance)
            $win->read(6);

            // ISO 14496-12 §8.5.2.2: data_reference_index is 1-based
            $dataRefIndex = $win->readU16BE();

            if ($dataRefIndex === 0) {
                throw new ParseError('stsd sample entry data_reference_index must be >= 1', 1399);
            }

            // Use first entry only; skip parsing subsequent entries to
            // avoid implicit 'last entry wins' when entry_count > 1
            if (($result === []) && ($handlerType === 'vide')) {
                $normalizedFormat = $this->boxNavigator->normalizeFourcc($format);
                $result           = $this->videoParser->parseVideoSampleEntry($win, $entryEnd, $normalizedFormat);
            } elseif (($result === []) && ($handlerType === 'soun')) {
                $normalizedFormat = $this->boxNavigator->normalizeFourcc($format);
                $result           = $this->audioParser->parseSoundSampleEntry($win, $entryStart, $entryEnd, $entrySize, $normalizedFormat, $version);
            } elseif (($result === []) && ($handlerType === 'meta')) {
                // Capture the sample-entry format code for metadata handler tracks
                // (e.g. 'djmd' for DJI telemetry tracks).
                $normalizedFormat = $this->boxNavigator->normalizeFourcc($format);

                if ($normalizedFormat !== '') {
                    $result = ['metaFormat' => $normalizedFormat];
                }
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container', 1161);
        }

        return $result;
    }

    /**
     * Computes video frame rate from the stts (TimeToSampleBox).
     *
     * ISO/IEC 14496-12 §8.6.1: the stts box maps decoding time to sample
     * numbers via run-length encoded (sample_count, sample_delta) pairs.
     * Frame rate = (sum of sample counts) × timescale / (sum of sample_count × sample_delta).
     *
     * @param BoxDescriptor $stts           Time-to-sample box descriptor.
     * @param int           $mediaTimescale Media timescale from the mdhd box.
     *
     * @return float|null Computed frame rate in frames per second, or null when undetermined.
     */
    private function computeFrameRateFromStts(BoxDescriptor $stts, int $mediaTimescale): ?float
    {
        $win = $stts->window;
        $win->seek(0);

        if ($stts->contentSize < 8) {
            throw new ParseError('stts box truncated', 2101);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            throw new ParseError('unsupported stts box version', 2102);
        }

        if ($header->flags !== 0) {
            throw new ParseError('unsupported stts box flags', 2103);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount === 0) {
            return null;
        }

        if ($entryCount > self::MAX_STTS_ENTRIES) {
            throw new ParseError('stts entry count exceeds maximum allowed', 2104);
        }

        $expectedSize = 8 + ($entryCount * 8);

        if ($stts->contentSize < $expectedSize) {
            throw new ParseError('stts entries truncated', 2105);
        }

        $totalSamples = 0;
        $totalTicks   = 0.0;

        for ($i = 0; $i < $entryCount; ++$i) {
            $sampleCount = $win->readU32BE();
            $sampleDelta = $win->readU32BE();

            if ($sampleDelta === 0) {
                return null;
            }

            $totalSamples += $sampleCount;
            $totalTicks   += (float) $sampleCount * $sampleDelta;
        }

        if (($totalTicks <= 0.0) || ($totalSamples <= 0)) {
            return null;
        }

        $fps = ($totalSamples * (float) $mediaTimescale) / $totalTicks;

        if (($fps <= 0.0) || !is_finite($fps)) {
            return null;
        }

        return $fps;
    }

    /**
     * Converts a Mac OS epoch timestamp to a formatted UTC date string.
     *
     * ISO/IEC 14496-12 §8.2.2: creation_time and modification_time are measured
     * in seconds since Jan 1, 1904. A value of 0 means "undefined".
     *
     * @param int $macTimestamp Seconds since 1904-01-01 00:00:00 UTC.
     *
     * @return string Formatted date string in 'Y:m:d H:i:s' format (UTC).
     */
    private function formatMacTimestamp(int $macTimestamp): string
    {
        $unixTimestamp = $macTimestamp - self::MAC_EPOCH_OFFSET;

        return gmdate('Y:m:d H:i:s', $unixTimestamp);
    }
}
