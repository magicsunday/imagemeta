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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function array_key_exists;
use function in_array;
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

    private VideoSampleEntryParser $videoParser;

    private AudioSampleEntryParser $audioParser;

    /**
     * @param BoxNavigator $boxNavigator   Shared box navigation infrastructure.
     * @param Closure      $processUdtaBox Callback for processing udta boxes (delegates to IsoBmffParser::parseUdtaBox).
     * @param Closure      $validateDinf   Callback for validating dinf boxes (delegates to ItemLocationResolver::parseDinf).
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
            throw new ParseError('mvhd box truncated', 1906);
        }

        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mvhd box version', 1908);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mvhd box flags', 1407);
        }

        // version 0: 4+4+4+4 + 76 = 96 after FullBox header (including rate, volume, matrix, etc.)
        // version 1: 8+8+4+8 + 76 = 108 after FullBox header
        $minPayload = $version === 1 ? 112 : 100;
        if ($mvhd->contentSize < $minPayload) {
            throw new ParseError('mvhd box truncated', 1907);
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
        $flags   = $this->boxNavigator->readUInt24($win);

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
     */
    private function parseMdhd(BoxDescriptor $mdhd): int
    {
        $win = $mdhd->window;
        $win->seek(0);

        if ($mdhd->contentSize < 4) {
            throw new ParseError('mdhd box truncated', 1901);
        }

        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mdhd box version', 1903);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mdhd box flags', 1904);
        }

        // version 0: 4+4+4+4+2+2 = 20 bytes after header; version 1: 8+8+4+8+2+2 = 32 bytes
        $minPayload = $version === 1 ? 36 : 24;
        if ($mdhd->contentSize < $minPayload) {
            throw new ParseError('mdhd box truncated', 1902);
        }

        if ($version === 1) {
            $win->read(8 + 8); // creation_time(64), modification_time(64)
        } else {
            $win->read(4 + 4); // creation_time(32), modification_time(32)
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('mdhd timescale must not be zero', 1905);
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
     * @return array{0: ?string, 1: ?string, 2: SampleEntryMap}
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
     * @return SampleEntryMap
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

        foreach ($this->boxNavigator->walkChildren($minf) as $child) {
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
            throw new ParseError('minf must contain exactly one stbl box', 1896);
        }

        if ($dinfCount === 0) {
            throw new ParseError('minf must contain exactly one dinf box', 1897);
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
     * @return SampleEntryMap
     */
    private function parseStbl(BoxDescriptor $stbl, string $handlerType, ?int $mdhdTimescale): array
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
     * @param BoxDescriptor $stsd          Sample description descriptor.
     * @param string        $handlerType   Handler type describing the media kind.
     * @param int|null      $mdhdTimescale Parsed mdhd timescale used for audio timing validation.
     *
     * @return SampleEntryMap
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
                $normalizedFormat = $this->boxNavigator->normalizeFourcc($format);
                $result           = $this->videoParser->parseVideoSampleEntry($win, $entryEnd, $normalizedFormat);
            } elseif ($result === [] && $handlerType === 'soun') {
                $normalizedFormat = $this->boxNavigator->normalizeFourcc($format);
                $result           = $this->audioParser->parseSoundSampleEntry($win, $entryStart, $entryEnd, $entrySize, $normalizedFormat, $version, $mdhdTimescale);
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container', 1161);
        }

        return $result;
    }
}
