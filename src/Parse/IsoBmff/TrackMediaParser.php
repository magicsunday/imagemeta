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
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

use function array_key_exists;
use function bin2hex;
use function in_array;
use function intdiv;
use function is_finite;
use function is_float;
use function mb_check_encoding;
use function ord;
use function pack;
use function preg_match;
use function round;
use function rtrim;
use function sprintf;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Parses track and media boxes within ISO BMFF containers, extracting
 * codec, resolution, and audio/video metadata from sample descriptions.
 *
 * @phpstan-type QuickTimeKeyMap = array<string, string|int|float|bool>
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
     * LPCM flag: payload stores IEEE floating-point samples.
     */
    private const int LPCM_FLAG_IS_FLOAT = 1 << 0;

    /**
     * LPCM flag: payload uses big-endian byte order.
     */
    private const int LPCM_FLAG_IS_BIG_ENDIAN = 1 << 1;

    /**
     * LPCM flag: integer payload uses signed samples.
     */
    private const int LPCM_FLAG_IS_SIGNED_INTEGER = 1 << 2;

    /**
     * LPCM flag: samples are tightly packed.
     */
    private const int LPCM_FLAG_IS_PACKED = 1 << 3;

    /**
     * LPCM flag: aligned samples are high-aligned.
     */
    private const int LPCM_FLAG_IS_ALIGNED_HIGH = 1 << 4;

    /**
     * Allowed QuickTime visual sample-entry depth values.
     *
     * QuickTime File Format 2012, "Video Sample Description".
     *
     * @var list<int>
     */
    private const array QUICKTIME_VIDEO_DEPTH_VALUES = [1, 2, 4, 8, 16, 24, 32, 34, 36, 40];

    /**
     * Depth values that must not reference color tables.
     *
     * @var list<int>
     */
    private const array QUICKTIME_VIDEO_NO_COLOR_TABLE_DEPTHS = [16, 24, 32];

    /**
     * Maximum number of sample entries in an stsd box to prevent DoS attacks.
     */
    private const int MAX_STSD_ENTRIES = 100;

    /**
     * @param Stream  $stream         Stream to read box data from.
     * @param Closure $processUdtaBox Callback for processing udta boxes (delegates to IsoBmffParser::parseUdtaBox).
     * @param Closure $validateDinf   Callback for validating dinf boxes (delegates to ItemLocationResolver::parseDinf).
     */
    public function __construct(
        private Stream $stream,
        private Closure $processUdtaBox,
        private Closure $validateDinf,
    ) {
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

        foreach ($this->walkChildren($trak) as $child) {
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
                    throw new ParseError('duplicate udta box in trak', 1418);
                }

                ($this->processUdtaBox)($child, $context);
            }
        }

        if ($tkhdCount === 0) {
            throw new ParseError('trak must contain exactly one tkhd box', 1376);
        }

        if ($mdiaCount === 0) {
            throw new ParseError('trak must contain exactly one mdia box', 1377);
        }

        if ($handler === null) {
            return [
                'handler'          => null,
                'isEnabledInMovie' => $isEnabledInMovie,
                'keys'             => [],
            ];
        }

        if (
            $handlerName !== null
            && $handlerName !== ''
            && !array_key_exists(QuickTimeMeta::HANDLER_DESCRIPTION_KEY, $context->qtKeys)
        ) {
            $context->qtKeys[QuickTimeMeta::HANDLER_DESCRIPTION_KEY] = $handlerName;
        }

        /** @var QuickTimeKeyMap $trackKeys */
        $trackKeys = [];

        if ($handler === 'vide') {
            $width  = $sampleInfo['width'] ?? $tkhdWidth;
            $height = $sampleInfo['height'] ?? $tkhdHeight;

            if ($width !== null && $width > 0) {
                $trackKeys[QuickTimeMeta::VIDEO_WIDTH_KEY] = $width;
            }

            if ($height !== null && $height > 0) {
                $trackKeys[QuickTimeMeta::VIDEO_HEIGHT_KEY] = $height;
            }

            if (isset($sampleInfo['format']) && $sampleInfo['format'] !== '') {
                $trackKeys[QuickTimeMeta::VIDEO_CODEC_KEY] = $sampleInfo['format'];
            }

            if (isset($sampleInfo['compressorName']) && $sampleInfo['compressorName'] !== '') {
                $trackKeys[QuickTimeMeta::COMPRESSOR_NAME_KEY] = $sampleInfo['compressorName'];
            }

            if (isset($sampleInfo['horizontalResolution'])) {
                $trackKeys[QuickTimeMeta::VIDEO_HORIZONTAL_RESOLUTION_KEY] = $sampleInfo['horizontalResolution'];
            }

            if (isset($sampleInfo['verticalResolution'])) {
                $trackKeys[QuickTimeMeta::VIDEO_VERTICAL_RESOLUTION_KEY] = $sampleInfo['verticalResolution'];
            }

            if (isset($sampleInfo['frameCount']) && $sampleInfo['frameCount'] !== 1) {
                $trackKeys[QuickTimeMeta::VIDEO_FRAME_COUNT_KEY] = $sampleInfo['frameCount'];
            }
        } elseif ($handler === 'soun') {
            if (isset($sampleInfo['format']) && $sampleInfo['format'] !== '') {
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

        return [
            'handler'          => $handler,
            'isEnabledInMovie' => $isEnabledInMovie,
            'keys'             => $trackKeys,
        ];
    }

    /**
     * Validates the movie header box (`mvhd`).
     *
     * ISO/IEC 14496-12 §8.2.2: the mvhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale and next_track_ID fields
     * must be non-zero.
     *
     * @param BoxDescriptor $mvhd Movie header box descriptor.
     */
    public function parseMvhd(BoxDescriptor $mvhd): void
    {
        $win = $mvhd->window;
        $win->seek(0);

        if ($mvhd->contentSize < 4) {
            throw new ParseError('mvhd box truncated', 1405);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mvhd box version', 1406);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mvhd box flags', 1407);
        }

        // version 0: 4+4+4+4 + 76 = 96 after FullBox header (including rate, volume, matrix, etc.)
        // version 1: 8+8+4+8 + 76 = 108 after FullBox header
        $minPayload = $version === 1 ? 112 : 100;
        if ($mvhd->contentSize < $minPayload) {
            throw new ParseError('mvhd box truncated', 1405);
        }

        if ($version === 1) {
            $win->read(8 + 8); // creation_time(64), modification_time(64)
        } else {
            $win->read(4 + 4); // creation_time(32), modification_time(32)
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('mvhd timescale must not be zero', 1408);
        }

        // Skip duration
        if ($version === 1) {
            $win->read(8); // duration(64)
        } else {
            $win->read(4); // duration(32)
        }

        // Skip rate(4), volume(2), reserved(10), matrix(36), pre_defined(24) = 76 bytes
        $win->read(76);

        $nextTrackId = $win->readU32BE();

        if ($nextTrackId === 0) {
            throw new ParseError('mvhd next_track_ID must not be zero', 1409);
        }
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

        $versionFlags = $win->read(4);
        $version      = ord($versionFlags[0]);
        $flags        = (ord($versionFlags[1]) << 16) | (ord($versionFlags[2]) << 8) | ord($versionFlags[3]);

        if ($version !== 0) {
            throw new ParseError('unsupported hdlr box version', 1148);
        }

        if ($flags !== 0) {
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
        // metadata extraction.  (GH-1534)

        $handlerType = $this->normaliseFourcc($handler);
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

                if ($trimmed !== '' && !mb_check_encoding($trimmed, 'UTF-8')) {
                    throw new ParseError('hdlr handler name contains invalid UTF-8', 1384);
                }

                $name = $trimmed !== '' ? $trimmed : null;
            } else {
                throw new ParseError(sprintf(
                    'hdlr handler name missing NUL terminator (counted length %d exceeds remaining %d bytes)',
                    $countedLen,
                    $remaining - 1,
                ), 1152);
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

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported tkhd box version', 1145);
        }

        // ISO/IEC 14496-12 §8.3.2: version 0 uses 32-bit timestamps, version 1 uses 64-bit
        if ($version === 1) {
            if ($tkhd->contentSize < 96) {
                throw new ParseError('tkhd version 1 box truncated', 1146);
            }

            $win->read(8 + 8); // creation(64), modification(64)
            $trackId    = $win->readU32BE();
            $reserved32 = $win->read(4);
            $win->read(8); // duration(64)
        } else {
            $win->read(4 + 4); // creation(32), modification(32)
            $trackId    = $win->readU32BE();
            $reserved32 = $win->read(4);
            $win->read(4); // duration(32)
        }

        // ISO/IEC 14496-12 §8.3.2: track_ID must be non-zero
        if ($trackId === 0) {
            throw new ParseError('tkhd track_ID must not be zero', 1369);
        }

        // ISO/IEC 14496-12 §8.3.2: reserved field after track_ID must be zero
        if ($reserved32 !== "\0\0\0\0") {
            throw new ParseError('tkhd reserved field after track_ID must be zero', 1370);
        }

        $reserved64 = $win->read(8); // reserved

        if ($reserved64 !== "\0\0\0\0\0\0\0\0") {
            throw new ParseError('tkhd reserved 8-byte field must be zero', 1371);
        }

        $win->read(2); // layer
        $win->read(2); // alternate group
        $win->read(2); // volume

        $reserved16 = $win->read(2); // reserved

        if ($reserved16 !== "\0\0") {
            throw new ParseError('tkhd reserved 2-byte field must be zero', 1372);
        }

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
     * Validates the media header box (`mdhd`).
     *
     * ISO/IEC 14496-12 §8.4.2: the mdhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale field must be non-zero.
     *
     * @param BoxDescriptor $mdhd Media header box descriptor.
     *
     * @return int
     */
    private function parseMdhd(BoxDescriptor $mdhd): int
    {
        $win = $mdhd->window;
        $win->seek(0);

        if ($mdhd->contentSize < 4) {
            throw new ParseError('mdhd box truncated', 1400);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mdhd box version', 1401);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mdhd box flags', 1402);
        }

        // version 0: 4+4+4+4+2+2 = 20 bytes after header; version 1: 8+8+4+8+2+2 = 32 bytes
        $minPayload = $version === 1 ? 36 : 24;
        if ($mdhd->contentSize < $minPayload) {
            throw new ParseError('mdhd box truncated', 1400);
        }

        if ($version === 1) {
            $win->read(8 + 8); // creation_time(64), modification_time(64)
        } else {
            $win->read(4 + 4); // creation_time(32), modification_time(32)
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('mdhd timescale must not be zero', 1403);
        }

        return $timescale;
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
     * @return array{0: ?string, 1: ?string, 2: array<string, int|float|string|bool>}
     */
    private function parseMdia(BoxDescriptor $mdia, IsoBmffParseContext $context): array
    {
        $handler       = null;
        $handlerName   = null;
        $sampleInfo    = [];
        $hdlrCount     = 0;
        $minfCount     = 0;
        $mdhdCount     = 0;
        $udtaCount     = 0;
        $mdhdTimescale = null;

        // Collect children first so hdlr/minf order does not matter
        $children = [];

        foreach ($this->walkChildren($mdia) as $child) {
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

                $mdhdTimescale = $this->parseMdhd($child);
            } elseif ($child->type === BoxType::UDTA->value) {
                ++$udtaCount;

                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in mdia', 1463);
                }

                ($this->processUdtaBox)($child, $context);
            }
        }

        if ($hdlrCount === 0) {
            throw new ParseError('mdia must contain exactly one hdlr box', 1378);
        }

        if ($minfCount === 0) {
            throw new ParseError('mdia must contain exactly one minf box', 1379);
        }

        if ($mdhdCount === 0) {
            throw new ParseError('mdia must contain exactly one mdhd box', 1380);
        }

        // Parse minf after hdlr so handler type is always available
        foreach ($children as $child) {
            $sampleInfo = $this->parseMinf($child, $handler, $mdhdTimescale);
        }

        return [$handler, $handlerName, $sampleInfo];
    }

    /**
     * Parses the media information box (`minf`) to find sample table details.
     *
     * @param BoxDescriptor $minf          Media information descriptor.
     * @param string|null   $handlerType   Declared handler type for the media.
     * @param int|null      $mdhdTimescale Parsed mdhd timescale used for audio timing validation.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseMinf(BoxDescriptor $minf, ?string $handlerType, ?int $mdhdTimescale): array
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

        foreach ($this->walkChildren($minf) as $child) {
            if ($child->type === BoxType::STBL->value) {
                ++$stblCount;

                if ($stblCount > 1) {
                    throw new ParseError('minf must contain exactly one stbl box', 1381);
                }

                $result = $this->parseStbl($child, $handlerType, $mdhdTimescale);
            } elseif ($child->type === BoxType::DINF->value) {
                ++$dinfCount;

                if ($dinfCount > 1) {
                    throw new ParseError('minf must contain exactly one dinf box', 1382);
                }

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
            throw new ParseError('minf must contain exactly one stbl box', 1381);
        }

        if ($dinfCount === 0) {
            throw new ParseError('minf must contain exactly one dinf box', 1382);
        }

        // Validate media header presence and handler match
        if ($mediaHdrType === null) {
            throw new ParseError(sprintf('minf missing required media header box %s for handler %s', $expectedMediaHdr, $handlerType), 1422);
        }

        if ($mediaHdrType !== $expectedMediaHdr) {
            throw new ParseError(sprintf('minf media header %s does not match handler %s (expected %s)', $mediaHdrType, $handlerType, $expectedMediaHdr), 1423);
        }

        return $result;
    }

    /**
     * Parses the sample table box (`stbl`).
     *
     * @param BoxDescriptor $stbl          Sample table descriptor.
     * @param string        $handlerType   Media handler type.
     * @param int|null      $mdhdTimescale Parsed mdhd timescale used for audio timing validation.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseStbl(BoxDescriptor $stbl, string $handlerType, ?int $mdhdTimescale): array
    {
        $stsdCount = 0;
        $sttsCount = 0;
        $stscCount = 0;
        $stszCount = 0;
        $stcoCount = 0;
        $result    = [];

        foreach ($this->walkChildren($stbl) as $child) {
            if ($child->type === BoxType::STSD->value) {
                ++$stsdCount;

                if ($stsdCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsd box', 1383);
                }

                $result = $this->parseStsd($child, $handlerType, $mdhdTimescale);
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
            throw new ParseError('stbl must contain exactly one stsd box', 1383);
        }

        // Enforce mandatory core sample-table boxes
        if ($sttsCount === 0) {
            throw new ParseError('stbl must contain exactly one stts box', 1424);
        }

        if ($stscCount === 0) {
            throw new ParseError('stbl must contain exactly one stsc box', 1425);
        }

        if ($stszCount === 0) {
            throw new ParseError('stbl must contain exactly one stsz or stz2 box', 1426);
        }

        if ($stcoCount === 0) {
            throw new ParseError('stbl must contain exactly one stco or co64 box', 1427);
        }

        return $result;
    }

    /**
     * Parses the sample description box (`stsd`).
     *
     * @param BoxDescriptor $stsd        Sample description descriptor.
     * @param string        $handlerType Handler type describing the media kind.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseStsd(BoxDescriptor $stsd, string $handlerType, ?int $mdhdTimescale): array
    {
        $win = $stsd->window;
        $win->seek(0);

        if ($stsd->contentSize < 8) {
            throw new ParseError('stsd box truncated', 1153);
        }

        $versionFlags = $win->read(4);
        $version      = ord($versionFlags[0]);
        $flags        = (ord($versionFlags[1]) << 16) | (ord($versionFlags[2]) << 8) | ord($versionFlags[3]);

        // ISO/IEC 14496-12 §8.5.2.2/§8.5.2.3: stsd is a FullBox with flags=0.
        // Version 1 is only valid in audio sample-description context.
        if (($version !== 0) && ($version !== 1)) {
            throw new ParseError('unsupported stsd box version', 1154);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported stsd box flags', 1155);
        }

        if (($version === 1) && ($handlerType !== 'soun')) {
            throw new ParseError('stsd version 1 requires audio handler context', 1465);
        }

        $entryCount = $win->readU32BE();

        // ISO/IEC 14496-12 §8.5.2: Sample Description Box must contain at least one entry.
        if ($entryCount === 0) {
            throw new ParseError('stsd entry count must be at least 1', 1466);
        }

        if ($entryCount > self::MAX_STSD_ENTRIES) {
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

            // ISO 14496-12 §8.5.2.2: the 6-byte reserved field must be all zeros
            $reserved6 = $win->read(6);
            if ($reserved6 !== "\0\0\0\0\0\0") {
                throw new ParseError('stsd sample entry reserved field must be zero', 1398);
            }

            // ISO 14496-12 §8.5.2.2: data_reference_index is 1-based
            $dataRefIndex = $win->readU16BE();
            if ($dataRefIndex === 0) {
                throw new ParseError('stsd sample entry data_reference_index must be >= 1', 1399);
            }

            // Use first entry only; skip parsing subsequent entries to
            // avoid implicit 'last entry wins' when entry_count > 1
            if ($result === [] && $handlerType === 'vide') {
                if ($win->tell() + 70 > $entryEnd) {
                    throw new ParseError('video sample entry truncated', 1159);
                }

                $win->readU16BE(); // version
                $videoRevisionLevel = $win->readU16BE();
                $win->readU32BE(); // vendor
                $temporalQuality = $win->readU32BE();
                $spatialQuality  = $win->readU32BE();

                if ($videoRevisionLevel !== 0) {
                    throw new ParseError('video sample entry revision level must be 0', 1499);
                }

                if ($temporalQuality > 1023) {
                    throw new ParseError('video sample entry temporal quality must be <= 1023', 1500);
                }

                if ($spatialQuality > 1024) {
                    throw new ParseError('video sample entry spatial quality must be <= 1024', 1501);
                }

                $width  = $win->readU16BE();
                $height = $win->readU16BE();

                if ($width === 0) {
                    throw new ParseError('video sample entry width must be > 0', 1601);
                }

                if ($height === 0) {
                    throw new ParseError('video sample entry height must be > 0', 1602);
                }

                $horizontalResolution = $this->decodeVideoResolution16_16($win->readU32BE(), 'horizontal');
                $verticalResolution   = $this->decodeVideoResolution16_16($win->readU32BE(), 'vertical');

                $dataSize = $win->readU32BE();
                if ($dataSize !== 0) {
                    throw new ParseError('video sample entry data size must be 0', 1502);
                }

                $frameCount = $win->readU16BE();
                if ($frameCount === 0) {
                    throw new ParseError('video sample entry frame count must be > 0', 1606);
                }

                // Decode compressorName as strict 32-byte Pascal string
                $nameLength = $win->readU8();
                $nameData   = $win->read(31);

                if ($nameLength > 31) {
                    throw new ParseError('compressorName Pascal string length exceeds 31', 1428);
                }

                $compressor = $nameLength > 0 ? substr($nameData, 0, $nameLength) : '';

                $depth        = $win->readU16BE();
                $colorTableId = $this->decodeSigned16($win->readU16BE());
                $this->validateVideoSampleEntryDepthAndColorTable($depth, $colorTableId, $win, $entryEnd);

                $result = [
                    'format'               => $this->normaliseFourcc($format),
                    'width'                => $width,
                    'height'               => $height,
                    'horizontalResolution' => $horizontalResolution,
                    'verticalResolution'   => $verticalResolution,
                    'frameCount'           => $frameCount,
                    'compressorName'       => $compressor,
                ];
            } elseif ($result === [] && $handlerType === 'soun') {
                $result = $this->parseSoundSampleEntry($win, $entryStart, $entryEnd, $entrySize, $format, $version, $mdhdTimescale);
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container', 1161);
        }

        return $result;
    }

    /**
     * Validates QuickTime visual sample-entry depth and color-table semantics.
     *
     * @param int          $depth        Visual sample-entry depth field.
     * @param int          $colorTableId Signed color-table identifier field.
     * @param StreamWindow $win          Reader positioned at trailing sample-entry payload.
     * @param int          $entryEnd     Absolute sample-entry end offset.
     */
    private function validateVideoSampleEntryDepthAndColorTable(int $depth, int $colorTableId, StreamWindow $win, int $entryEnd): void
    {
        if (!in_array($depth, self::QUICKTIME_VIDEO_DEPTH_VALUES, true)) {
            throw new ParseError('video sample entry depth is not allowed by QuickTime domain', 1494);
        }

        if (in_array($depth, self::QUICKTIME_VIDEO_NO_COLOR_TABLE_DEPTHS, true) && $colorTableId !== -1) {
            throw new ParseError('video sample entry depth without color table must use colorTableId -1', 1495);
        }

        if ($colorTableId === 0) {
            $tailOffset = $win->tell();
            $remaining  = $entryEnd - $tailOffset;

            if ($remaining < 8) {
                throw new ParseError('video sample entry colorTableId=0 requires trailing ctab atom', 1496);
            }

            $colorTableSize = Unpack::int('N', $win->read(4), 'video sample entry ctab atom size');
            $colorTableType = $win->read(4);

            if ($colorTableType !== 'ctab') {
                throw new ParseError('video sample entry colorTableId=0 requires trailing ctab atom', 1496);
            }

            if ($colorTableSize < 8 || $colorTableSize > $remaining) {
                throw new ParseError('video sample entry ctab atom is truncated', 1498);
            }

            $win->seek($tailOffset + $colorTableSize);
        }

        $this->validateVideoSampleEntryTrailingPayload($win, $entryEnd);
    }

    /**
     * Validates trailing bytes in visual sample entries.
     *
     * Accepts empty tails, coherent child-box sequences, and an optional final
     * 4-byte zero terminator documented by QuickTime.
     */
    private function validateVideoSampleEntryTrailingPayload(StreamWindow $win, int $entryEnd): void
    {
        $offset = $win->tell();

        while ($offset < $entryEnd) {
            $remaining = $entryEnd - $offset;

            if ($remaining === 4) {
                $win->seek($offset);
                if ($win->read(4) !== pack('N', 0)) {
                    throw new ParseError('video sample entry trailing payload is malformed', 1497);
                }

                return;
            }

            if ($remaining < 8) {
                throw new ParseError('video sample entry trailing payload is malformed', 1497);
            }

            $win->seek($offset);
            $boxSize = $win->readU32BE();

            if ($boxSize < 8 || $boxSize > $remaining) {
                throw new ParseError('video sample entry trailing payload is malformed', 1497);
            }

            $offset += $boxSize;
        }
    }

    /**
     * Decodes a 16-bit unsigned value as signed two's-complement integer.
     */
    private function decodeSigned16(int $value): int
    {
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    /**
     * Parses an audio sample entry from `stsd`, handling sound description versions 0, 1, and 2.
     *
     * QuickTime File Format 2012, "Sound Sample Description" (v0/v1/v2):
     * - v0 uses the legacy channel/sample-size/16.16 rate layout.
     * - v1 appends four 32-bit packet/frame sizing fields.
     * - v2 repurposes the legacy words as required constants and appends
     *   Core Audio-style channel/rate fields.
     *
     * @param StreamWindow $win           Reader positioned at the beginning of sample-entry specific fields.
     * @param int          $entryStart    Absolute offset of the sample-entry specific fields.
     * @param int          $entryEnd      Absolute offset where this sample entry ends.
     * @param int          $entrySize     Declared sample entry size (including size+type header).
     * @param string       $format        Raw fourcc format code.
     * @param int          $stsdVersion   FullBox version of the enclosing stsd.
     * @param int|null     $mdhdTimescale Parsed mdhd timescale used for audio timing validation.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseSoundSampleEntry(
        StreamWindow $win,
        int $entryStart,
        int $entryEnd,
        int $entrySize,
        string $format,
        int $stsdVersion,
        ?int $mdhdTimescale,
    ): array {
        if ($win->tell() + 8 > $entryEnd) {
            throw new ParseError('audio sample entry truncated', 1160);
        }

        $version       = $win->readU16BE();
        $revisionLevel = $win->readU16BE();
        $vendor        = $win->readU32BE();

        if ($revisionLevel !== 0) {
            throw new ParseError('audio sample entry revision level must be 0', 1455);
        }

        if ($vendor !== 0) {
            throw new ParseError('audio sample entry vendor must be 0', 1456);
        }

        if ($version === 1 && $stsdVersion !== 1) {
            throw new ParseError('audio sample entry version 1 requires stsd version 1', 1472);
        }

        if ($version === 2) {
            $result = $this->parseSoundSampleEntryVersion2($win, $entryStart, $entryEnd, $entrySize, $format);

            $samplingRateOverride = $this->parseAudioSampleEntrySamplingRateBox($win, $entryEnd, false);
            if ($samplingRateOverride !== null) {
                $result['sampleRate'] = $samplingRateOverride;
            }

            return $result;
        }

        if ($version !== 0 && $version !== 1) {
            throw new ParseError(sprintf('unsupported audio sample entry version %d', $version), 1457);
        }

        if ($win->tell() + 12 > $entryEnd) {
            throw new ParseError('audio sample entry truncated', 1160);
        }

        $channels         = $win->readU16BE();
        $sampleSize       = $win->readU16BE();
        $compressionId    = $win->readU16BE();
        $packetSize       = $win->readU16BE();
        $normalizedFormat = $this->normaliseFourcc($format);

        if ($version === 0) {
            if ($channels !== 1 && $channels !== 2) {
                throw new ParseError('audio sample entry version 0 channels must be 1 or 2', 1503);
            }

            if ($sampleSize !== 8 && $sampleSize !== 16) {
                throw new ParseError('audio sample entry version 0 sample size must be 8 or 16 bits', 1504);
            }

            if ($compressionId !== 0) {
                throw new ParseError('audio sample entry version 0 compression ID must be 0', 1505);
            }

            if ($packetSize !== 0) {
                throw new ParseError('audio sample entry version 0 packet size must be 0', 1506);
            }

            $legacyFormat = rtrim($normalizedFormat, ' ');
            if ($legacyFormat !== 'raw' && $legacyFormat !== 'twos') {
                throw new ParseError('audio sample entry version 0 format must be "raw " or "twos"', 1507);
            }
        }

        $sampleRateRaw = $win->readU32BE();
        if ($version === 0 && $sampleRateRaw > 0xFFFF0000) {
            throw new ParseError('audio sample entry version 0 sampleRate must be <= 65535', 1508);
        }

        $sampleRate = $this->decodeAudioSampleRate16_16($sampleRateRaw);

        if ($version === 1) {
            if ($win->tell() + 16 > $entryEnd) {
                throw new ParseError('audio sample entry version 1 extension truncated', 1458);
            }

            // QuickTime File Format 2012, "Sound Sample Description (Version 1)":
            // samplesPerPacket, bytesPerPacket, bytesPerFrame, bytesPerSample.
            $win->readU32BE();
            $win->readU32BE();
            $win->readU32BE();
            $win->readU32BE();
        }

        $samplingRateOverride = $this->parseAudioSampleEntrySamplingRateBox($win, $entryEnd, $version === 1);
        if ($samplingRateOverride !== null) {
            if ($samplingRateOverride <= 0) {
                throw new ParseError('audio sample rate must be positive', 1485);
            }

            $sampleRate = $samplingRateOverride;
        }

        $this->validateAudioSampleRateTimescaleRelation($sampleRate, $mdhdTimescale);

        return [
            'format'        => $normalizedFormat,
            'channels'      => $channels,
            'bitsPerSample' => $sampleSize,
            'sampleRate'    => $sampleRate,
        ];
    }

    /**
     * Parses trailing AudioSampleEntry child boxes and extracts Sampling Rate box overrides.
     *
     * @param StreamWindow $win                  Reader positioned at the start of trailing child bytes.
     * @param int          $entryEnd             Absolute offset where this sample entry ends.
     * @param bool         $allowSamplingRateBox Whether a `srat` box is allowed in this entry version.
     *
     * @return int|null
     */
    private function parseAudioSampleEntrySamplingRateBox(StreamWindow $win, int $entryEnd, bool $allowSamplingRateBox): ?int
    {
        $remaining = $entryEnd - $win->tell();
        if ($remaining <= 0) {
            return null;
        }

        $tail     = $win->read($remaining);
        $tailSize = strlen($tail);
        $offset   = 0;
        $override = null;

        while ($offset + 8 <= $tailSize) {
            $boxSize = Unpack::int('N', substr($tail, $offset, 4), 'audio sample entry child box size');

            if (($boxSize < 8) || (($offset + $boxSize) > $tailSize)) {
                break;
            }

            $boxType = substr($tail, $offset + 4, 4);
            if ($boxType === BoxType::SRAT->value) {
                if (!$allowSamplingRateBox) {
                    throw new ParseError('sampling rate box is only allowed in audio sample entry version 1', 1473);
                }

                if ($boxSize < 12) {
                    throw new ParseError('sampling rate box truncated', 1474);
                }

                $override = Unpack::int('N', substr($tail, $offset + 8, 4), 'sampling rate box sample rate');
            }

            $offset += $boxSize;
        }

        return $override;
    }

    /**
     * Decodes a QuickTime video sample-entry resolution 16.16 fixed-point value.
     *
     * @param int    $resolutionRaw Raw unsigned 16.16 fixed-point value.
     * @param string $axis          Resolution axis (`horizontal` or `vertical`) for diagnostics.
     *
     * @return int|float
     */
    private function decodeVideoResolution16_16(int $resolutionRaw, string $axis): int|float
    {
        if ($resolutionRaw <= 0) {
            throw new ParseError(sprintf('video sample entry %s resolution must be > 0', $axis), 1604);
        }

        if (($resolutionRaw & 0x80000000) !== 0) {
            throw new ParseError(sprintf('video sample entry %s resolution exceeds supported 16.16 range', $axis), 1605);
        }

        $integerPart = $resolutionRaw >> 16;
        if ($integerPart <= 0) {
            throw new ParseError(sprintf('video sample entry %s resolution must be > 0', $axis), 1604);
        }

        $fractionalPart = $resolutionRaw & 0xFFFF;
        if ($fractionalPart === 0) {
            return $integerPart;
        }

        return $resolutionRaw / 65536.0;
    }

    /**
     * Decodes an AudioSampleEntry 16.16 fixed-point sample rate.
     *
     * @param int $sampleRateRaw Raw 16.16 fixed-point value from the sample entry.
     *
     * @return int|float
     */
    private function decodeAudioSampleRate16_16(int $sampleRateRaw): int|float
    {
        if ($sampleRateRaw <= 0) {
            throw new ParseError('audio sample rate must be positive', 1485);
        }

        $integerPart = $sampleRateRaw >> 16;
        if ($integerPart <= 0) {
            throw new ParseError('audio sample rate must be positive', 1485);
        }

        $fractionalPart = $sampleRateRaw & 0xFFFF;
        if ($fractionalPart === 0) {
            return $integerPart;
        }

        return $sampleRateRaw / 65536.0;
    }

    /**
     * Validates audio sample rate and mdhd timescale relation (equal or integer multiple/division).
     *
     * Fractional legacy 16.16 rates are preserved and excluded from the integer-relation check.
     *
     * @param int|float $sampleRate    Parsed audio sample rate in Hz.
     * @param int|null  $mdhdTimescale Parsed mdhd timescale.
     *
     * @return void
     */
    private function validateAudioSampleRateTimescaleRelation(int|float $sampleRate, ?int $mdhdTimescale): void
    {
        if ($mdhdTimescale === null || $mdhdTimescale <= 0) {
            return;
        }

        if (is_float($sampleRate)) {
            return;
        }

        if (($mdhdTimescale % $sampleRate) !== 0 && ($sampleRate % $mdhdTimescale) !== 0) {
            throw new ParseError('audio sample rate and mdhd timescale must be equal or integer multiple/division', 1484);
        }
    }

    /**
     * Parses sound sample description version 2 and validates required constant fields.
     *
     * QuickTime File Format 2012, "Sound Sample Description (Version 2)":
     * - always3 = 3
     * - always16 = 16
     * - alwaysMinus2 = -2
     * - always0 = 0
     * - always65536 = 65536
     * - always7F000000 = 0x7F000000
     * - sizeOfStructOnly points to the start of extension atoms.
     *
     * @param StreamWindow $win        Reader positioned after version/revision/vendor.
     * @param int          $entryStart Absolute offset of sample-entry fields (after size+type).
     * @param int          $entryEnd   Absolute offset where this sample entry ends.
     * @param int          $entrySize  Declared sample entry size (including size+type header).
     * @param string       $format     Raw fourcc format code.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseSoundSampleEntryVersion2(StreamWindow $win, int $entryStart, int $entryEnd, int $entrySize, string $format): array
    {
        if ($win->tell() + 48 > $entryEnd) {
            throw new ParseError('audio sample entry version 2 truncated', 1459);
        }

        $always3                       = $win->readU16BE();
        $always16                      = $win->readU16BE();
        $alwaysMinus2                  = $win->readU16BE();
        $always0                       = $win->readU16BE();
        $always65536                   = $win->readU32BE();
        $sizeOfStructOnly              = $win->readU32BE();
        $audioSampleRate               = Unpack::float('E', $win->read(8), 'audio sample entry version 2 sample rate');
        $numChannels                   = $win->readU32BE();
        $always7F000000                = $win->readU32BE();
        $bitsPerChannel                = $win->readU32BE();
        $formatSpecificFlags           = $win->readU32BE();
        $constBytesPerAudioPacket      = $win->readU32BE();
        $constLpcmFramesPerAudioPacket = $win->readU32BE();

        if (
            $always3 !== 3
            || $always16 !== 16
            || $alwaysMinus2 !== 0xFFFE
            || $always0 !== 0
            || $always65536 !== 65536
            || $always7F000000 !== 0x7F000000
        ) {
            throw new ParseError('audio sample entry version 2 constants are invalid', 1460);
        }

        // sizeOfStructOnly points from sample entry start to extension atoms.
        if (($sizeOfStructOnly < 72) || ($sizeOfStructOnly > $entrySize)) {
            throw new ParseError('audio sample entry version 2 sizeOfStructOnly is invalid', 1461);
        }

        $entryBodySize = $entryEnd - $entryStart;
        if ($sizeOfStructOnly - 8 > $entryBodySize) {
            throw new ParseError('audio sample entry version 2 sizeOfStructOnly exceeds entry bounds', 1462);
        }

        if ($numChannels === 0) {
            throw new ParseError('audio sample entry version 2 channel count must be positive', 1486);
        }

        if (!is_finite($audioSampleRate) || $audioSampleRate <= 0.0) {
            throw new ParseError('audio sample entry version 2 sample rate must be positive', 1487);
        }

        $sampleRate = (int) round($audioSampleRate);
        if ($sampleRate <= 0) {
            throw new ParseError('audio sample entry version 2 sample rate must be positive', 1487);
        }

        $normalizedFormat = $this->normaliseFourcc($format);

        $result = [
            'format'        => $normalizedFormat,
            'channels'      => $numChannels,
            'bitsPerSample' => $bitsPerChannel > 0 ? $bitsPerChannel : $always16,
            'sampleRate'    => $sampleRate,
        ];

        if ($normalizedFormat !== 'lpcm') {
            return $result;
        }

        if ($bitsPerChannel === 0) {
            throw new ParseError('lpcm bitsPerChannel must be positive', 1488);
        }

        if ($constBytesPerAudioPacket === 0) {
            throw new ParseError('lpcm constBytesPerAudioPacket must be positive', 1489);
        }

        if ($constLpcmFramesPerAudioPacket === 0) {
            throw new ParseError('lpcm constLPCMFramesPerAudioPacket must be positive', 1490);
        }

        $isFloat         = ($formatSpecificFlags & self::LPCM_FLAG_IS_FLOAT) !== 0;
        $isBigEndian     = ($formatSpecificFlags & self::LPCM_FLAG_IS_BIG_ENDIAN) !== 0;
        $isSignedInteger = ($formatSpecificFlags & self::LPCM_FLAG_IS_SIGNED_INTEGER) !== 0;
        $isPacked        = ($formatSpecificFlags & self::LPCM_FLAG_IS_PACKED) !== 0;
        $isAlignedHigh   = ($formatSpecificFlags & self::LPCM_FLAG_IS_ALIGNED_HIGH) !== 0;

        if ($isFloat && $isSignedInteger) {
            throw new ParseError('lpcm format flags cannot set both float and signed-integer bits', 1491);
        }

        $minBytesPerAudioPacket = $this->calculateLpcmMinBytesPerAudioPacket(
            $bitsPerChannel,
            $numChannels,
            $constLpcmFramesPerAudioPacket,
        );

        if ($isPacked && $constBytesPerAudioPacket !== $minBytesPerAudioPacket) {
            throw new ParseError('lpcm constBytesPerAudioPacket must match packed channel/bit-depth layout', 1492);
        }

        if (!$isPacked && $constBytesPerAudioPacket < $minBytesPerAudioPacket) {
            throw new ParseError('lpcm constBytesPerAudioPacket is too small for aligned channel/bit-depth layout', 1493);
        }

        $result[QuickTimeMeta::AUDIO_LPCM_FORMAT_FLAGS_KEY]      = $formatSpecificFlags;
        $result[QuickTimeMeta::AUDIO_LPCM_NUMERIC_FORMAT_KEY]    = $isFloat ? 'float' : 'integer';
        $result[QuickTimeMeta::AUDIO_LPCM_ENDIANNESS_KEY]        = $isBigEndian ? 'big' : 'little';
        $result[QuickTimeMeta::AUDIO_LPCM_PACKING_KEY]           = $isPacked ? 'packed' : 'aligned';
        $result[QuickTimeMeta::AUDIO_LPCM_IS_FLOAT_KEY]          = $isFloat;
        $result[QuickTimeMeta::AUDIO_LPCM_IS_SIGNED_INTEGER_KEY] = $isSignedInteger;
        $result[QuickTimeMeta::AUDIO_LPCM_IS_BIG_ENDIAN_KEY]     = $isBigEndian;
        $result[QuickTimeMeta::AUDIO_LPCM_IS_PACKED_KEY]         = $isPacked;
        $result[QuickTimeMeta::AUDIO_LPCM_IS_ALIGNED_HIGH_KEY]   = $isAlignedHigh;
        $result[QuickTimeMeta::AUDIO_LPCM_BYTES_PER_PACKET_KEY]  = $constBytesPerAudioPacket;
        $result[QuickTimeMeta::AUDIO_LPCM_FRAMES_PER_PACKET_KEY] = $constLpcmFramesPerAudioPacket;

        return $result;
    }

    /**
     * Calculates the minimum bytes required per audio packet for LPCM sample layouts.
     */
    private function calculateLpcmMinBytesPerAudioPacket(int $bitsPerChannel, int $numChannels, int $framesPerPacket): int
    {
        $bytesPerSample = intdiv($bitsPerChannel + 7, 8);

        return $bytesPerSample * $numChannels * $framesPerPacket;
    }

    /**
     * Normalises a four-character code into a printable identifier.
     *
     * @param string $fourcc Raw four-character code bytes.
     */
    private function normaliseFourcc(string $fourcc): string
    {
        if ($this->isPrintableFourcc($fourcc)) {
            return $fourcc;
        }

        return strtoupper(bin2hex($fourcc));
    }

    /**
     * Checks whether a four-character code contains printable ASCII.
     *
     * @param string $fourcc Four-character code to test.
     *
     * @return bool
     */
    private function isPrintableFourcc(string $fourcc): bool
    {
        if (strlen($fourcc) !== 4) {
            return false;
        }

        if (preg_match('/^[\x20-\x7E]{4}$/', $fourcc) === 1) {
            return true;
        }

        return preg_match('/^\xA9[\x20-\x7E]{3}$/', $fourcc) === 1;
    }

    /**
     * Iterates through child boxes within a container, yielding descriptors.
     *
     * @param BoxDescriptor $parent                  Parent box descriptor whose content is iterated.
     * @param int           $offset                  Optional relative byte offset where iteration begins.
     * @param bool          $allowTrailingTerminator When true, tolerates a trailing 4-byte zero terminator
     *                                               at the end of the child list. QuickTime File Format 2012
     *                                               §2 "User Data Atoms" specifies that a udta list may
     *                                               optionally end with a 32-bit integer set to 0.
     *
     * @return iterable<BoxDescriptor>
     */
    private function walkChildren(BoxDescriptor $parent, int $offset = 0, bool $allowTrailingTerminator = false): iterable
    {
        if ($offset < 0 || $offset > $parent->contentSize) {
            throw new ParseError('child offset outside container', 1258);
        }

        $limit  = $parent->contentOffset + $parent->contentSize;
        $cursor = $parent->contentOffset + $offset;
        $end    = $parent->contentOffset + $parent->contentSize;

        while ($cursor + 8 <= $end) {
            $box = $this->readBoxAt($cursor, $limit);
            yield $box;
            $cursor += $box->size;
        }

        if ($cursor !== $end) {
            // QuickTime File Format 2012 §2 "User Data Atoms": a udta child
            // list may optionally end with a 32-bit zero terminator.
            if ($allowTrailingTerminator && (($end - $cursor) === 4)) {
                $this->stream->seek($cursor);
                if ($this->stream->readU32BE() === 0) {
                    return;
                }
            }

            throw new ParseError('child boxes do not align with parent', 1259);
        }
    }

    /**
     * Reads a box header at the given offset and returns a descriptor object.
     *
     * @param int $offset Absolute byte offset of the box within the stream.
     * @param int $limit  Limit offset that bounds the container.
     *
     * @return BoxDescriptor
     */
    private function readBoxAt(int $offset, int $limit, bool $allowImplicitSize = false): BoxDescriptor
    {
        if ($offset < 0 || $offset > $limit) {
            throw new ParseError('box offset outside container', 1260);
        }

        $this->stream->seek($offset);
        $size32     = $this->stream->readU32BE();
        $type       = $this->stream->read(4);
        $headerSize = 8;
        $size       = $size32;

        if ($size32 === 0) {
            if (!$allowImplicitSize) {
                throw new ParseError('nested box size==0 is only valid at top level', 1362);
            }

            $size = $limit - $offset;
        } elseif ($size32 === 1) {
            $size = $this->stream->readU64BE()->toInt('extended box size');
            $headerSize += 8;
        }

        $userType = null;
        if ($type === BoxType::UUID->value) {
            // uuid box must be at least 24 bytes (8-byte header + 16-byte userType)
            if ($size < 24) {
                throw new ParseError('uuid box size must be at least 24 bytes', 1420);
            }

            $userType = $this->stream->read(16);
            $headerSize += 16;
        }

        if ($size < $headerSize) {
            throw new ParseError('invalid box size for ' . $type, 1261);
        }

        if ($offset + $size > $limit) {
            // Truncated recordings (e.g. interrupted drone/camera captures)
            // commonly have an mdat header written with the intended full
            // recording size while the file ends mid-stream.  Clamping the
            // effective size lets the parser continue scanning for metadata
            // boxes that may follow (or precede) the mdat.
            if ($type === 'mdat' && $allowImplicitSize) {
                $size = $limit - $offset;
            } else {
                throw new ParseError(
                    sprintf('box %s exceeds container bounds', $type), 1262);
            }
        }

        $contentOffset = $offset + $headerSize;
        $contentSize   = $size - $headerSize;
        $window        = $this->stream->window($contentOffset, $contentSize);

        return new BoxDescriptor(
            $type,
            $size,
            $offset,
            $contentOffset,
            $contentSize,
            $window,
            $userType,
        );
    }

    /**
     * Reads an unsigned 24-bit integer from the provided window.
     *
     * @param StreamWindow $window Window to read from.
     *
     * @return int
     */
    private function readUInt24(StreamWindow $window): int
    {
        return $this->readUInt($window, 3);
    }

    /**
     * Reads an unsigned integer using the specified byte width.
     *
     * @param StreamWindow $window Window to read from.
     * @param int          $bytes  Number of bytes representing the integer.
     *
     * @return int
     */
    private function readUInt(StreamWindow $window, int $bytes): int
    {
        return match ($bytes) {
            0       => 0,
            1       => $window->readU8(),
            2       => $window->readU16BE(),
            3       => Unpack::int('N', "\0" . $window->read(3), '24-bit integer value'),
            4       => $window->readU32BE(),
            8       => $window->readU64BE()->toInt('64-bit integer value'),
            default => throw new ParseError('unsupported integer size ' . $bytes, 1256),
        };
    }
}
