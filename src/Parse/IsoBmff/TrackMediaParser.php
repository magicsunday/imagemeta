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
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function array_key_exists;
use function array_map;
use function atan2;
use function chr;
use function implode;
use function in_array;
use function mb_check_encoding;
use function number_format;
use function ord;
use function round;
use function rtrim;
use function sprintf;
use function substr;

use const M_PI;

/**
 * Parses track and media boxes within ISO BMFF containers, extracting
 * codec, resolution, and audio/video metadata from sample descriptions.
 *
 * ISO/IEC 14496-12 §8.4 defines the track structure and §8.5 the media boxes.
 *
 * @phpstan-type QuickTimeKeyMap    = array<string, string|int|float|bool>
 * @phpstan-type SampleEntryMap     = array<string, int|float|string|bool>
 * @phpstan-type MediaHdrInfo       = array<string, int|float|string>
 * @phpstan-type TkhdResult         = array{width: ?int, height: ?int, isEnabledInMovie: bool, createDate: int, modifyDate: int, trackId: int, duration: int, layer: int, volume: float, rotation: int, matrix: string}
 * @phpstan-type MdhdResult         = array{createDate: int, modifyDate: int, timescale: int, duration: int, language: string}
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
        $tkhdResult   = null;
        $handler      = null;
        $handlerName  = null;
        $sampleInfo   = [];
        $mediaHdrInfo = [];

        /** @var MdhdResult|null $mdhdData */
        $mdhdData         = null;
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

                $tkhdResult       = $this->parseTkhd($child);
                $isEnabledInMovie = $tkhdResult['isEnabledInMovie'];
            } elseif ($child->type === BoxType::MDIA->value) {
                ++$mdiaCount;

                if ($mdiaCount > 1) {
                    throw new ParseError('trak must contain exactly one mdia box', 1377);
                }

                [$handler, $handlerName, $sampleInfo, $mdhdData, $mediaHdrInfo] = $this->parseMdia($child, $context);
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

        if (($handler === null) || ($mdhdData === null)) {
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
            'vide'  => $this->buildVideoTrackKeys($sampleInfo, $tkhdResult, $mdhdData, $mediaHdrInfo),
            'soun'  => $this->buildAudioTrackKeys($sampleInfo, $tkhdResult, $mdhdData, $mediaHdrInfo),
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
     * @param SampleEntryMap  $sampleInfo
     * @param TkhdResult|null $tkhdResult
     * @param MdhdResult      $mdhdData
     * @param MediaHdrInfo    $mediaHdrInfo
     *
     * @return QuickTimeKeyMap
     */
    private function buildVideoTrackKeys(array $sampleInfo, ?array $tkhdResult, array $mdhdData, array $mediaHdrInfo): array
    {
        $tkhdWidth  = $tkhdResult['width'] ?? null;
        $tkhdHeight = $tkhdResult['height'] ?? null;
        $width      = $sampleInfo['width'] ?? $tkhdWidth;
        $height     = $sampleInfo['height'] ?? $tkhdHeight;

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

        if (isset($sampleInfo['depth'])) {
            $trackKeys[QuickTimeMeta::VIDEO_BIT_DEPTH_KEY] = $sampleInfo['depth'];
        }

        if (isset($mediaHdrInfo['graphicsMode'])) {
            $trackKeys[QuickTimeMeta::GRAPHICS_MODE_KEY] = $mediaHdrInfo['graphicsMode'];
        }

        if (isset($mediaHdrInfo['opColor'])) {
            $trackKeys[QuickTimeMeta::OP_COLOR_KEY] = $mediaHdrInfo['opColor'];
        }

        $this->applyTkhdKeys($tkhdResult, $trackKeys);
        $this->applyMdhdKeys($mdhdData, $trackKeys);

        return $trackKeys;
    }

    /**
     * Builds QuickTime keys for audio tracks.
     *
     * @param SampleEntryMap  $sampleInfo
     * @param TkhdResult|null $tkhdResult
     * @param MdhdResult      $mdhdData
     * @param MediaHdrInfo    $mediaHdrInfo
     *
     * @return QuickTimeKeyMap
     */
    private function buildAudioTrackKeys(array $sampleInfo, ?array $tkhdResult, array $mdhdData, array $mediaHdrInfo): array
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

        if (isset($mediaHdrInfo['balance'])) {
            $trackKeys[QuickTimeMeta::BALANCE_KEY] = $mediaHdrInfo['balance'];
        }

        $this->copyLpcmSampleInfoKeys($sampleInfo, $trackKeys);
        $this->applyTkhdKeys($tkhdResult, $trackKeys);
        $this->applyMdhdKeys($mdhdData, $trackKeys);

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
     * Applies track-header metadata fields to the track key map.
     *
     * @param TkhdResult|null $tkhdResult Parsed tkhd result (null when tkhd was absent).
     * @param QuickTimeKeyMap $trackKeys  Track key map to populate (modified in-place).
     */
    private function applyTkhdKeys(?array $tkhdResult, array &$trackKeys): void
    {
        if ($tkhdResult === null) {
            return;
        }

        $trackKeys[QuickTimeMeta::TRACK_CREATE_DATE_KEY] = $tkhdResult['createDate'];
        $trackKeys[QuickTimeMeta::TRACK_MODIFY_DATE_KEY] = $tkhdResult['modifyDate'];
        $trackKeys[QuickTimeMeta::TRACK_ID_KEY]          = $tkhdResult['trackId'];
        $trackKeys[QuickTimeMeta::TRACK_DURATION_KEY]    = $tkhdResult['duration'];
        $trackKeys[QuickTimeMeta::TRACK_LAYER_KEY]       = $tkhdResult['layer'];
        $trackKeys[QuickTimeMeta::TRACK_VOLUME_KEY]      = $tkhdResult['volume'];
        $trackKeys[QuickTimeMeta::ROTATION_KEY]          = $tkhdResult['rotation'];
        $trackKeys[QuickTimeMeta::TRACK_MATRIX_KEY]      = $tkhdResult['matrix'];
    }

    /**
     * Applies media-header metadata fields to the track key map.
     *
     * @param MdhdResult      $mdhdData  Parsed mdhd result.
     * @param QuickTimeKeyMap $trackKeys Track key map to populate (modified in-place).
     */
    private function applyMdhdKeys(array $mdhdData, array &$trackKeys): void
    {
        $trackKeys[QuickTimeMeta::MEDIA_CREATE_DATE_KEY]   = $mdhdData['createDate'];
        $trackKeys[QuickTimeMeta::MEDIA_MODIFY_DATE_KEY]   = $mdhdData['modifyDate'];
        $trackKeys[QuickTimeMeta::MEDIA_TIME_SCALE_KEY]    = $mdhdData['timescale'];
        $trackKeys[QuickTimeMeta::MEDIA_DURATION_KEY]      = $mdhdData['duration'];
        $trackKeys[QuickTimeMeta::MEDIA_LANGUAGE_CODE_KEY] = $mdhdData['language'];
    }

    /**
     * Parses the movie header box (`mvhd`) and returns extracted metadata.
     *
     * ISO/IEC 14496-12 §8.2.2: the mvhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale and next_track_ID fields
     * must be non-zero.
     *
     * @param BoxDescriptor $mvhd Movie header box descriptor.
     *
     * @return array<string, int|float|string>
     */
    public function parseMvhd(BoxDescriptor $mvhd): array
    {
        $header = $this->parseTimescaleHeader($mvhd, 100, 112, 1906, 1908, 1407, 1907, 1408);

        $win     = $mvhd->window;
        $version = $header['version'];

        // Duration: 8 bytes for v1, 4 bytes for v0
        $duration = $version === 1 ? $win->readU64BE()->toInt('mvhd duration') : $win->readU32BE();

        // Rate: 16.16 fixed-point (4 bytes)
        $rateRaw = $win->readU32BE();
        $rate    = $this->decodeSigned16_16($rateRaw);

        // Volume: 8.8 fixed-point (2 bytes)
        $volumeRaw = $win->readU16BE();
        $volume    = $volumeRaw / 256.0;

        // Reserved (10 bytes)
        $win->read(10);

        // Matrix (36 bytes = 9 x u32)
        $matrixRaw = $this->readMatrixRaw($win);
        $matrix    = $this->formatMatrix($matrixRaw);

        // QuickTime-specific fields (overlap ISO pre_defined[6])
        $previewTime       = $win->readU32BE();
        $previewDuration   = $win->readU32BE();
        $posterTime        = $win->readU32BE();
        $selectionTime     = $win->readU32BE();
        $selectionDuration = $win->readU32BE();
        $currentTime       = $win->readU32BE();

        $nextTrackId = $win->readU32BE();

        if ($nextTrackId === 0) {
            throw new ParseError('mvhd next_track_ID must not be zero', 1409);
        }

        return [
            'createDate'        => $header['createDate'],
            'modifyDate'        => $header['modifyDate'],
            'timescale'         => $header['timescale'],
            'duration'          => $duration,
            'preferredRate'     => $rate,
            'preferredVolume'   => $volume,
            'matrix'            => $matrix,
            'previewTime'       => $previewTime,
            'previewDuration'   => $previewDuration,
            'posterTime'        => $posterTime,
            'selectionTime'     => $selectionTime,
            'selectionDuration' => $selectionDuration,
            'currentTime'       => $currentTime,
            'nextTrackID'       => $nextTrackId,
        ];
    }

    /**
     * Decodes a signed 16.16 fixed-point integer to a float.
     *
     * @param int $raw Unsigned 32-bit value representing a signed 16.16 fixed-point number.
     */
    private function decodeSigned16_16(int $raw): float
    {
        if ($raw >= 0x80000000) {
            $raw -= 0x100000000;
        }

        return $raw / 65536.0;
    }

    /**
     * Reads 9 consecutive unsigned 32-bit big-endian values forming a 3x3 matrix.
     *
     * @return list<int>
     */
    private function readMatrixRaw(StreamWindow $win): array
    {
        $raw = [];

        for ($i = 0; $i < 9; ++$i) {
            $raw[] = $win->readU32BE();
        }

        return $raw;
    }

    /**
     * Formats a raw 3x3 matrix into a space-separated string of decoded values.
     *
     * Positions 0,1,3,4,6,7 are signed 16.16 fixed-point; positions 2,5,8 are 2.30 fixed-point.
     *
     * @param list<int> $matrixRaw Nine unsigned 32-bit values.
     */
    private function formatMatrix(array $matrixRaw): string
    {
        $values = [];

        for ($i = 0; $i < 9; ++$i) {
            $raw      = $matrixRaw[$i];
            $values[] = (($i % 3) === 2) ? $raw / 1_073_741_824.0 : $this->decodeSigned16_16($raw);
        }

        return implode(' ', array_map(
            static fn (float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'),
            $values,
        ));
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
     * Parses the track header box (`tkhd`) and extracts all metadata fields.
     *
     * ISO/IEC 14496-12 §8.3.2: the tkhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The track_ID field must be non-zero.
     *
     * @param BoxDescriptor $tkhd Track header descriptor.
     *
     * @return TkhdResult
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

            $createDate = $win->readU64BE()->toInt('tkhd creation_time');
            $modifyDate = $win->readU64BE()->toInt('tkhd modification_time');
            $trackId    = $win->readU32BE();
            $win->read(4); // reserved
            $duration = $win->readU64BE()->toInt('tkhd duration');
        } else {
            $createDate = $win->readU32BE();
            $modifyDate = $win->readU32BE();
            $trackId    = $win->readU32BE();
            $win->read(4); // reserved
            $duration = $win->readU32BE();
        }

        // ISO/IEC 14496-12 §8.3.2: track_ID must be non-zero
        if ($trackId === 0) {
            throw new ParseError('tkhd track_ID must not be zero', 1369);
        }

        $win->read(8); // reserved (64-bit)

        $layer = $this->decodeSigned16($win->readU16BE());
        $win->read(2); // alternate group
        $volume = $win->readU16BE() / 256.0;

        $win->read(2); // reserved (16-bit)

        $matrixRaw = $this->readMatrixRaw($win);
        $matrix    = $this->formatMatrix($matrixRaw);
        $rotation  = $this->computeRotation($matrixRaw);

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

        return [
            'width'            => $width,
            'height'           => $height,
            'isEnabledInMovie' => $isEnabledInMovie,
            'createDate'       => $createDate,
            'modifyDate'       => $modifyDate,
            'trackId'          => $trackId,
            'duration'         => $duration,
            'layer'            => $layer,
            'volume'           => $volume,
            'rotation'         => $rotation,
            'matrix'           => $matrix,
        ];
    }

    /**
     * Decodes an unsigned 16-bit value as a signed 16-bit integer.
     *
     * @param int $value Unsigned 16-bit value (0..65535).
     */
    private function decodeSigned16(int $value): int
    {
        return ($value >= 0x8000) ? $value - 0x10000 : $value;
    }

    /**
     * Computes the rotation angle in degrees from a raw 3x3 transformation matrix.
     *
     * Uses the `a` (index 0) and `b` (index 1) elements of the matrix, both encoded
     * as signed 16.16 fixed-point values, to derive the angle via `atan2(b, a)`.
     * The result is normalised to the range [0, 360).
     *
     * @param list<int> $matrixRaw Nine unsigned 32-bit values.
     */
    private function computeRotation(array $matrixRaw): int
    {
        $a       = $this->decodeSigned16_16($matrixRaw[0]);
        $b       = $this->decodeSigned16_16($matrixRaw[1]);
        $radians = atan2($b, $a);
        $degrees = (int) round($radians * 180.0 / M_PI);

        return (($degrees % 360) + 360) % 360;
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
     * @return array{version: int, createDate: int, modifyDate: int, timescale: int}
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
            $createDate = $win->readU64BE()->toInt('creation_time');
            $modifyDate = $win->readU64BE()->toInt('modification_time');
        } else {
            $createDate = $win->readU32BE();
            $modifyDate = $win->readU32BE();
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('timescale must not be zero', $timescaleCode);
        }

        return [
            'version'    => $version,
            'createDate' => $createDate,
            'modifyDate' => $modifyDate,
            'timescale'  => $timescale,
        ];
    }

    /**
     * Parses the media header box (`mdhd`).
     *
     * ISO/IEC 14496-12 §8.4.2: the mdhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale field must be non-zero.
     * After creation/modification/timescale, the box contains duration, a packed
     * ISO 639-2/T language code, and a reserved quality field.
     *
     * @param BoxDescriptor $mdhd Media header box descriptor.
     *
     * @return MdhdResult
     */
    private function parseMdhd(BoxDescriptor $mdhd): array
    {
        $header = $this->parseTimescaleHeader($mdhd, 24, 36, 1901, 1903, 1904, 1902, 1905);
        $win    = $mdhd->window;

        // Duration: 8 bytes for v1, 4 bytes for v0
        $duration = ($header['version'] === 1)
            ? $win->readU64BE()->toInt('mdhd duration')
            : $win->readU32BE();

        // Language: packed ISO 639-2/T (3x5-bit characters)
        $packed   = $win->readU16BE();
        $language = $this->decodeIso639Language($packed);

        return [
            'createDate' => $header['createDate'],
            'modifyDate' => $header['modifyDate'],
            'timescale'  => $header['timescale'],
            'duration'   => $duration,
            'language'   => $language,
        ];
    }

    /**
     * Decodes a packed ISO 639-2/T language code (3x5-bit characters).
     *
     * ISO/IEC 14496-12 §8.4.2: bit 15 is pad (0), bits 14-10 = char1,
     * bits 9-5 = char2, bits 4-0 = char3. Each 5-bit value maps to
     * 0x60 + value (1='a', 26='z').
     */
    private function decodeIso639Language(int $packed): string
    {
        $c1 = ($packed >> 10) & 0x1F;
        $c2 = ($packed >> 5) & 0x1F;
        $c3 = $packed & 0x1F;

        if (($c1 === 0) && ($c2 === 0) && ($c3 === 0)) {
            return 'und';
        }

        return chr(0x60 + $c1) . chr(0x60 + $c2) . chr(0x60 + $c3);
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
     * @return array{0: ?string, 1: ?string, 2: SampleEntryMap, 3: MdhdResult, 4: MediaHdrInfo}
     */
    private function parseMdia(BoxDescriptor $mdia, IsoBmffParseContext $context): array
    {
        $handler      = null;
        $handlerName  = null;
        $sampleInfo   = [];
        $mediaHdrInfo = [];
        $mdhdData     = null;
        $hdlrCount    = 0;
        $minfCount    = 0;
        $mdhdCount    = 0;
        $udtaCount    = 0;

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

                $mdhdData = $this->parseMdhd($child);
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

        if (($mdhdCount === 0) || ($mdhdData === null)) {
            throw new ParseError('mdia must contain exactly one mdhd box', 1895);
        }

        // Parse minf after hdlr so handler type is always available
        foreach ($children as $child) {
            [$sampleInfo, $mediaHdrInfo] = $this->parseMinf($child, $handler);
        }

        return [$handler, $handlerName, $sampleInfo, $mdhdData, $mediaHdrInfo];
    }

    /**
     * Parses the media information box (`minf`) to find sample table details.
     *
     * @param BoxDescriptor $minf        Media information descriptor.
     * @param string|null   $handlerType Declared handler type for the media.
     *
     * @return array{0: SampleEntryMap, 1: MediaHdrInfo}
     */
    private function parseMinf(BoxDescriptor $minf, ?string $handlerType): array
    {
        if ($handlerType === null) {
            return [[], []];
        }

        $stblCount    = 0;
        $dinfCount    = 0;
        $mediaHdrType = null;
        $mediaHdrInfo = [];
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

                $result = $this->parseStbl($child, $handlerType);
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

                if ($child->type === BoxType::VMHD->value) {
                    $mediaHdrInfo = $this->parseVmhd($child);
                } elseif ($child->type === BoxType::SMHD->value) {
                    $mediaHdrInfo = $this->parseSmhd($child);
                }
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
                return [$result, $mediaHdrInfo];
            }

            throw new ParseError(sprintf('minf missing required media header box %s for handler %s', $expectedMediaHdr, $handlerType), 1422);
        }

        return [$result, $mediaHdrInfo];
    }

    /**
     * Parses video media information header (vmhd).
     *
     * ISO/IEC 14496-12 §12.1.2: FullBox with version=0 and flags=0x000001.
     * Tolerate flags=0 for compatibility with non-conforming files.
     *
     * @return array{graphicsMode: int, opColor: string}
     */
    private function parseVmhd(BoxDescriptor $vmhd): array
    {
        $win = $vmhd->window;
        $win->seek(0);

        if ($vmhd->contentSize < 12) {
            throw new ParseError('vmhd box truncated', 2101);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            throw new ParseError('unsupported vmhd box version', 2102);
        }

        if (($header->flags !== 0) && ($header->flags !== 1)) {
            throw new ParseError('unsupported vmhd box flags', 2106);
        }

        $graphicsMode = $win->readU16BE();
        $r            = $win->readU16BE();
        $g            = $win->readU16BE();
        $b            = $win->readU16BE();

        return [
            'graphicsMode' => $graphicsMode,
            'opColor'      => sprintf('%d %d %d', $r, $g, $b),
        ];
    }

    /**
     * Parses sound media information header (smhd).
     *
     * QuickTime File Format 2012, "Sound Media Information Header Atom":
     * FullBox with version=0 and flags=0. Balance is signed 8.8 fixed-point.
     *
     * @return array{balance: float}
     */
    private function parseSmhd(BoxDescriptor $smhd): array
    {
        $win = $smhd->window;
        $win->seek(0);

        if ($smhd->contentSize < 8) {
            throw new ParseError('smhd box truncated', 2103);
        }

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            throw new ParseError('unsupported smhd box version', 2104);
        }

        if ($header->flags !== 0) {
            throw new ParseError('unsupported smhd box flags', 2105);
        }

        $balanceRaw = $win->readU16BE();
        $balance    = $this->decodeSigned16($balanceRaw) / 256.0;

        return ['balance' => $balance];
    }

    /**
     * Parses the sample table box (`stbl`).
     *
     * @param BoxDescriptor $stbl        Sample table descriptor.
     * @param string        $handlerType Media handler type.
     *
     * @return SampleEntryMap
     */
    private function parseStbl(BoxDescriptor $stbl, string $handlerType): array
    {
        $stsdCount = 0;
        $sttsCount = 0;
        $stscCount = 0;
        $stszCount = 0;
        $stcoCount = 0;
        $result    = [];

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
                $result           = $this->audioParser->parseSoundSampleEntry($win, $entryStart, $entryEnd, $entrySize, $normalizedFormat);
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container', 1161);
        }

        return $result;
    }
}
