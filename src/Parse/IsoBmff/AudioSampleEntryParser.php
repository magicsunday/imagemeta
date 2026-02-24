<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

use function intdiv;
use function is_finite;
use function is_float;
use function round;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * Parses audio codec sample-entry descriptions from ISO BMFF sample
 * description boxes (stsd), extracting channel count, sample rate,
 * bit depth, and LPCM format metadata per ISO/IEC 14496-12 §8.5.2.
 */
final readonly class AudioSampleEntryParser
{
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
     * Parses an audio sample entry from `stsd`, handling sound description versions 0, 1, and 2.
     *
     * QuickTime File Format 2012, "Sound Sample Description" (v0/v1/v2):
     * - v0 uses the legacy channel/sample-size/16.16 rate layout.
     * - v1 appends four 32-bit packet/frame sizing fields.
     * - v2 repurposes the legacy words as required constants and appends
     *   Core Audio-style channel/rate fields.
     *
     * @param StreamWindow $win              Reader positioned at the beginning of sample-entry specific fields.
     * @param int          $entryStart       Absolute offset of the sample-entry specific fields.
     * @param int          $entryEnd         Absolute offset where this sample entry ends.
     * @param int          $entrySize        Declared sample entry size (including size+type header).
     * @param string       $normalizedFormat Pre-normalized fourcc format string.
     * @param int          $stsdVersion      FullBox version of the enclosing stsd.
     * @param int|null     $mdhdTimescale    Parsed mdhd timescale used for audio timing validation.
     *
     * @return array<string, int|float|string|bool>
     */
    public function parseSoundSampleEntry(
        StreamWindow $win,
        int $entryStart,
        int $entryEnd,
        int $entrySize,
        string $normalizedFormat,
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
            $result = $this->parseSoundSampleEntryVersion2($win, $entryStart, $entryEnd, $entrySize, $normalizedFormat);

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

        $channels      = $win->readU16BE();
        $sampleSize    = $win->readU16BE();
        $compressionId = $win->readU16BE();
        $packetSize    = $win->readU16BE();

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
     * @param StreamWindow $win              Reader positioned after version/revision/vendor.
     * @param int          $entryStart       Absolute offset of sample-entry fields (after size+type).
     * @param int          $entryEnd         Absolute offset where this sample entry ends.
     * @param int          $entrySize        Declared sample entry size (including size+type header).
     * @param string       $normalizedFormat Pre-normalized fourcc format string.
     *
     * @return array<string, int|float|string|bool>
     */
    private function parseSoundSampleEntryVersion2(StreamWindow $win, int $entryStart, int $entryEnd, int $entrySize, string $normalizedFormat): array
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
     * Parses trailing AudioSampleEntry child boxes and extracts Sampling Rate box overrides.
     *
     * @param StreamWindow $win                  Reader positioned at the start of trailing child bytes.
     * @param int          $entryEnd             Absolute offset where this sample entry ends.
     * @param bool         $allowSamplingRateBox Whether a `srat` box is allowed in this entry version.
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
     * Validates audio sample rate and mdhd timescale relation (equal or integer multiple/division).
     *
     * Fractional legacy 16.16 rates are preserved and excluded from the integer-relation check.
     *
     * @param int|float $sampleRate    Parsed audio sample rate in Hz.
     * @param int|null  $mdhdTimescale Parsed mdhd timescale.
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
     * Decodes an AudioSampleEntry 16.16 fixed-point sample rate.
     *
     * @param int $sampleRateRaw Raw 16.16 fixed-point value from the sample entry.
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
     * Calculates the minimum bytes required per audio packet for LPCM sample layouts.
     */
    private function calculateLpcmMinBytesPerAudioPacket(int $bitsPerChannel, int $numChannels, int $framesPerPacket): int
    {
        $bytesPerSample = intdiv($bitsPerChannel + 7, 8);

        return $bytesPerSample * $numChannels * $framesPerPacket;
    }
}
