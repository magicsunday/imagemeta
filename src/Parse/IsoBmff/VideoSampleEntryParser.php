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

use function in_array;
use function pack;
use function substr;

/**
 * Parses video codec sample-entry descriptions from ISO BMFF sample
 * description boxes (stsd), extracting resolution, compressor, and
 * depth metadata per ISO/IEC 14496-12 §8.5.2.
 */
final readonly class VideoSampleEntryParser
{
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
     * Parses a video sample entry from `stsd`, extracting resolution, compressor,
     * depth, and codec metadata.
     *
     * The reader must be positioned after the common sample-entry header fields
     * (reserved + data_reference_index). This method reads: version(u16),
     * revisionLevel(u16), vendor(u32), temporalQuality(u32), spatialQuality(u32),
     * width(u16), height(u16), hRes(u32), vRes(u32), dataSize(u32),
     * frameCount(u16), compressorName(32 bytes), depth(u16), colorTableId(u16).
     *
     * @param StreamWindow $win              Reader positioned at the beginning of video sample-entry specific fields.
     * @param int          $entryEnd         Absolute offset where this sample entry ends.
     * @param string       $normalizedFormat Pre-normalized fourcc format string.
     *
     * @return array<string, int|float|string>
     */
    public function parseVideoSampleEntry(StreamWindow $win, int $entryEnd, string $normalizedFormat): array
    {
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

        return [
            'format'               => $normalizedFormat,
            'width'                => $width,
            'height'               => $height,
            'horizontalResolution' => $horizontalResolution,
            'verticalResolution'   => $verticalResolution,
            'frameCount'           => $frameCount,
            'compressorName'       => $compressor,
        ];
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
                throw new ParseError('video sample entry colorTableId=0 requires trailing ctab atom', 1931);
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
                    throw new ParseError('video sample entry trailing payload is malformed', 1932);
                }

                return;
            }

            if ($remaining < 8) {
                throw new ParseError('video sample entry trailing payload is malformed', 1933);
            }

            $win->seek($offset);
            $boxSize = $win->readU32BE();

            if ($boxSize < 8 || $boxSize > $remaining) {
                throw new ParseError('video sample entry trailing payload is malformed', 1934);
            }

            $offset += $boxSize;
        }
    }

    /**
     * Decodes a QuickTime video sample-entry resolution 16.16 fixed-point value.
     *
     * @param int    $resolutionRaw Raw unsigned 16.16 fixed-point value.
     * @param string $axis          Resolution axis (`horizontal` or `vertical`) for diagnostics.
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
            throw new ParseError(sprintf('video sample entry %s resolution must be > 0', $axis), 1938);
        }

        $fractionalPart = $resolutionRaw & 0xFFFF;
        if ($fractionalPart === 0) {
            return $integerPart;
        }

        return $resolutionRaw / 65536.0;
    }

    /**
     * Decodes a 16-bit unsigned value as signed two's-complement integer.
     */
    private function decodeSigned16(int $value): int
    {
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }
}
