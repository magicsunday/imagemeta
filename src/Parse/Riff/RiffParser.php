<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use Generator;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Riff\RiffAviHeader;
use MagicSunday\ImageMeta\Model\Riff\RiffExifChunk;
use MagicSunday\ImageMeta\Model\Riff\RiffInfo;

use function rtrim;

use const SEEK_CUR;

/**
 * Streaming RIFF/AVI reader that extracts metadata payloads.
 *
 * Walks the RIFF chunk hierarchy, extracting INFO metadata,
 * XMP packets (_PMX), RIFF-native EXIF fields (LIST 'exif'),
 * and embedded TIFF/EXIF blobs (strd with AVIF prefix).
 *
 * Reference: Microsoft AVI RIFF File Reference, ExifTool RIFF.pm.
 */
final class RiffParser implements RiffParserInterface
{
    /**
     * Minimum size of the AVIMAINHEADER structure in bytes (14 DWORDs).
     */
    private const int AVIH_MIN_SIZE = 56;

    /**
     * Minimum strd payload size for an AVIF-prefixed TIFF blob (4-byte tag + 4-byte skip + data).
     */
    private const int STRD_AVIF_MIN_SIZE = 9;

    /**
     * Size of the AVIF tag prefix and padding before the TIFF blob in strd.
     */
    private const int STRD_AVIF_HEADER_SIZE = 8;

    /**
     * Size of a RIFF chunk header (FourCC + uint32 size).
     */
    private const int CHUNK_HEADER_SIZE = 8;

    /** @var list<string> */
    private array $exifBlobs = [];

    /** @var list<string> */
    private array $xmpBlobs = [];

    /** @var array<string, string> */
    private array $infoFields = [];

    private ?RiffAviHeader $aviHeader = null;

    private ?RiffExifChunk $riffExif = null;

    private int $chunkCount = 0;

    public function __construct(
        private readonly Stream $stream,
        private readonly RiffParserConfig $config = new RiffParserConfig(),
    ) {
    }

    public function extract(): RiffParseResult
    {
        $this->stream->seek(0);

        $fileSize = $this->readRiffHeader();

        // Walk top-level chunks inside the first RIFF container
        $contentStart = 12;
        $contentEnd   = min($contentStart + $fileSize - 4, $this->stream->size());

        $this->walkChunks($contentStart, $contentEnd, 0);

        // Handle concatenated RIFF 'AVIX' continuation chunks
        $this->walkAvixContinuations($contentEnd);

        return new RiffParseResult(
            $this->exifBlobs,
            $this->xmpBlobs,
            $this->infoFields !== [] ? new RiffInfo($this->infoFields) : null,
            $this->aviHeader,
            $this->riffExif,
        );
    }

    /**
     * Reads and validates the 12-byte RIFF header.
     *
     * @return int The declared file size from the RIFF header.
     *
     * @throws ParseError when the signature is invalid or the form type is not AVI.
     */
    private function readRiffHeader(): int
    {
        try {
            $signature = $this->stream->read(4);
        } catch (BoundsError $exception) {
            throw new ParseError('RIFF header too short to read signature', 2115, $exception);
        }

        if ($signature !== 'RIFF') {
            throw new ParseError('Invalid RIFF signature', 2116);
        }

        try {
            $fileSize = $this->readU32LE();
            $formType = $this->stream->read(4);
        } catch (BoundsError $exception) {
            throw new ParseError('RIFF header truncated', 2117, $exception);
        }

        if ($formType !== 'AVI ') {
            throw new ParseError('Unsupported RIFF form type: expected AVI', 2118);
        }

        return $fileSize;
    }

    /**
     * Walks chunks sequentially within the given byte range.
     *
     * @param int $startOffset First byte of the first chunk header.
     * @param int $endOffset   Exclusive end boundary.
     * @param int $depth       Current LIST nesting depth.
     */
    private function walkChunks(int $startOffset, int $endOffset, int $depth): void
    {
        $offset = $startOffset;

        while (($offset + self::CHUNK_HEADER_SIZE) <= $endOffset) {
            if (++$this->chunkCount > $this->config->maxChunkCount) {
                throw new ParseError('RIFF chunk count exceeds limit', 2119);
            }

            $this->stream->seek($offset);

            try {
                $chunkId   = $this->stream->read(4);
                $chunkSize = $this->readU32LE();
            } catch (BoundsError) {
                break; // Postel's Law: stop on truncated header
            }

            $dataOffset = $offset + self::CHUNK_HEADER_SIZE;

            // WORD-aligned total: header + payload + optional pad byte
            $totalSize = self::CHUNK_HEADER_SIZE + $chunkSize + ($chunkSize & 1);

            if (($offset + $totalSize) > $endOffset) {
                // Postel's Law: truncated trailing chunk — stop walking
                break;
            }

            $this->dispatchChunk($chunkId, $dataOffset, $chunkSize, $depth);

            $offset += $totalSize;
        }
    }

    /**
     * Dispatches a chunk to the appropriate handler based on its FourCC.
     */
    private function dispatchChunk(string $chunkId, int $dataOffset, int $chunkSize, int $depth): void
    {
        switch ($chunkId) {
            case 'LIST':
                $this->handleList($dataOffset, $chunkSize, $depth);

                break;

            case '_PMX':
                $this->handleXmp($dataOffset, $chunkSize);

                break;

            case 'IDIT':
                $this->handleIdit($dataOffset, $chunkSize);

                break;

            case 'avih':
                $this->handleAviHeader($dataOffset, $chunkSize);

                break;
        }
    }

    /**
     * Handles a LIST chunk by reading its list type and dispatching to sub-parsers.
     */
    private function handleList(int $dataOffset, int $dataSize, int $depth): void
    {
        if ($depth >= $this->config->maxListDepth) {
            throw new ParseError('RIFF LIST nesting depth exceeds limit', 2123);
        }

        if ($dataSize < 4) {
            return; // LIST too short for list type FourCC — skip
        }

        $this->stream->seek($dataOffset);

        try {
            $listType = $this->stream->read(4);
        } catch (BoundsError) {
            return;
        }

        $childStart = $dataOffset + 4;
        $childEnd   = $dataOffset + $dataSize;

        switch ($listType) {
            case 'INFO':
            case 'INF0': // AlphaImagingTech misspelling (digit zero instead of letter O)
                $this->parseInfoList($childStart, $childEnd);

                break;

            case 'exif':
                $this->parseExifList($childStart, $childEnd);

                break;

            case 'hdrl':
                $this->walkChunks($childStart, $childEnd, $depth + 1);

                break;

            case 'strl':
                $this->parseStreamList($childStart, $childEnd);

                break;
        }
    }

    /**
     * Iterates sub-chunks within a bounded byte range, yielding tag, data offset, and size for each.
     *
     * Handles WORD-aligned padding and tolerates truncated trailing chunks (Postel's Law).
     *
     * @param int $startOffset First byte of the first chunk header.
     * @param int $endOffset   Exclusive end boundary.
     *
     * @return Generator<int, array{string, int, int}> Yields [tag, dataOffset, size].
     */
    private function iterateSubChunks(int $startOffset, int $endOffset): Generator
    {
        $offset = $startOffset;

        while (($offset + self::CHUNK_HEADER_SIZE) <= $endOffset) {
            $this->stream->seek($offset);

            try {
                $tag  = $this->stream->read(4);
                $size = $this->readU32LE();
            } catch (BoundsError) {
                break;
            }

            $dataOffset = $offset + self::CHUNK_HEADER_SIZE;
            $totalSize  = self::CHUNK_HEADER_SIZE + $size + ($size & 1);

            if (($offset + $totalSize) > $endOffset) {
                break; // Postel's Law: truncated trailing chunk
            }

            yield [$tag, $dataOffset, $size];

            $offset += $totalSize;
        }
    }

    /**
     * Reads a sub-chunk payload as a null-stripped string if within size limits.
     *
     * @return string|null The trimmed string, or null if empty/oversized/unreadable.
     */
    private function readSubChunkString(int $dataOffset, int $size): ?string
    {
        if (($size <= 0) || ($size > $this->config->maxMetadataPayloadSize)) {
            return null;
        }

        $this->stream->seek($dataOffset);

        try {
            $value = rtrim($this->stream->read($size), "\x00");
        } catch (BoundsError) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    /**
     * Parses INFO sub-chunks into key-value string pairs.
     *
     * RIFF 1991 section 3 — INFO List Chunk.
     */
    private function parseInfoList(int $startOffset, int $endOffset): void
    {
        foreach ($this->iterateSubChunks($startOffset, $endOffset) as [$tag, $dataOffset, $size]) {
            $value = $this->readSubChunkString($dataOffset, $size);

            if ($value !== null) {
                $this->infoFields[$tag] = $value;
            }
        }
    }

    /**
     * Parses RIFF-native EXIF sub-chunks (LIST 'exif').
     *
     * ExifTool RIFF.pm — %Image::ExifTool::RIFF::Exif tag table.
     */
    private function parseExifList(int $startOffset, int $endOffset): void
    {
        $make    = null;
        $model   = null;
        $time    = null;
        $comment = null;
        $version = null;
        $related = null;
        $maker   = null;

        foreach ($this->iterateSubChunks($startOffset, $endOffset) as [$tag, $dataOffset, $size]) {
            if ($size <= 0) {
                continue;
            }

            if ($size > $this->config->maxMetadataPayloadSize) {
                continue;
            }

            if ($tag === 'emnt') {
                // MakerNotes: binary — keep as-is without null stripping
                $this->stream->seek($dataOffset);

                try {
                    $maker = $this->stream->read($size);
                } catch (BoundsError) {
                    continue;
                }

                continue;
            }

            $value = $this->readSubChunkString($dataOffset, $size);

            match ($tag) {
                'ecor'  => $make    = $value,
                'emdl'  => $model   = $value,
                'etim'  => $time    = $value,
                'eucm'  => $comment = $value,
                'ever'  => $version = $value,
                'erel'  => $related = $value,
                default => null,
            };
        }

        $this->riffExif = new RiffExifChunk($make, $model, $time, $comment, $version, $related, $maker);
    }

    /**
     * Parses a stream list (LIST 'strl') looking for strd chunks with AVIF-prefixed TIFF blobs.
     */
    private function parseStreamList(int $startOffset, int $endOffset): void
    {
        foreach ($this->iterateSubChunks($startOffset, $endOffset) as [$tag, $dataOffset, $size]) {
            if ($tag !== 'strd') {
                continue;
            }

            if ($size < self::STRD_AVIF_MIN_SIZE) {
                continue;
            }

            $this->stream->seek($dataOffset);

            try {
                $prefix = $this->stream->read(4);
            } catch (BoundsError) {
                continue;
            }

            if ($prefix !== 'AVIF') {
                continue;
            }

            $blobSize = $size - self::STRD_AVIF_HEADER_SIZE;

            if ($blobSize > $this->config->maxMetadataPayloadSize) {
                continue;
            }

            $this->stream->seek($dataOffset + self::STRD_AVIF_HEADER_SIZE);

            try {
                $this->exifBlobs[] = $this->stream->read($blobSize);
            } catch (BoundsError) {
                // Postel's Law: skip truncated blob
            }
        }
    }

    /**
     * Handles a _PMX (XMP) chunk.
     */
    private function handleXmp(int $dataOffset, int $dataSize): void
    {
        if (($dataSize <= 0) || ($dataSize > $this->config->maxMetadataPayloadSize)) {
            return;
        }

        $this->stream->seek($dataOffset);

        try {
            $this->xmpBlobs[] = $this->stream->read($dataSize);
        } catch (BoundsError) {
            // Postel's Law: skip truncated XMP
        }
    }

    /**
     * Handles a top-level IDIT chunk (DateTimeOriginal string).
     */
    private function handleIdit(int $dataOffset, int $dataSize): void
    {
        if (($dataSize <= 0) || ($dataSize > $this->config->maxMetadataPayloadSize)) {
            return;
        }

        $this->stream->seek($dataOffset);

        try {
            $value = rtrim($this->stream->read($dataSize), "\x00");
        } catch (BoundsError) {
            return;
        }

        if ($value !== '') {
            $this->infoFields['IDIT'] = $value;
        }
    }

    /**
     * Handles an avih chunk (AVIMAINHEADER structure).
     */
    private function handleAviHeader(int $dataOffset, int $dataSize): void
    {
        // AVIMAINHEADER: 14 DWORDs = 56 bytes minimum
        if ($dataSize < self::AVIH_MIN_SIZE) {
            return; // Postel's Law: skip truncated headers
        }

        $this->stream->seek($dataOffset);

        try {
            $microSecPerFrame = $this->readU32LE();

            // Skip maxBytesPerSec (4) + paddingGranularity (4) + flags (4)
            $this->stream->seek(12, SEEK_CUR);
            $totalFrames = $this->readU32LE();

            // Skip initialFrames (4)
            $this->stream->seek(4, SEEK_CUR);
            $streams = $this->readU32LE();

            // Skip suggestedBufferSize (4)
            $this->stream->seek(4, SEEK_CUR);
            $width  = $this->readU32LE();
            $height = $this->readU32LE();
        } catch (BoundsError) {
            return; // Postel's Law: skip malformed header
        }

        $this->aviHeader = new RiffAviHeader(
            $microSecPerFrame,
            $width,
            $height,
            $totalFrames,
            $streams,
        );
    }

    /**
     * Scans for concatenated RIFF 'AVIX' continuation chunks after the first container.
     *
     * OpenDML AVI Extensions — large AVI files use RIFF 'AVIX' continuations.
     */
    private function walkAvixContinuations(int $startOffset): void
    {
        $offset = $startOffset + ($startOffset & 1); // WORD-align

        while (($offset + 12) <= $this->stream->size()) {
            $this->stream->seek($offset);

            try {
                $sig      = $this->stream->read(4);
                $contSize = $this->readU32LE();
                $formType = $this->stream->read(4);
            } catch (BoundsError) {
                break;
            }

            if (($sig !== 'RIFF') || ($formType !== 'AVIX')) {
                break;
            }

            $contStart = $offset + 12;
            $contEnd   = min($offset + self::CHUNK_HEADER_SIZE + $contSize, $this->stream->size());

            $this->walkChunks($contStart, $contEnd, 0);

            $nextOffset = $offset + self::CHUNK_HEADER_SIZE + $contSize;
            $offset     = $nextOffset + ($nextOffset & 1);
        }
    }

    /**
     * Reads an unsigned 32-bit little-endian integer from the stream.
     */
    private function readU32LE(): int
    {
        return Unpack::int('V', $this->stream->read(4), 'RIFF LE uint32');
    }
}
