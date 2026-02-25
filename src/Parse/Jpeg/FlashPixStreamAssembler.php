<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;

use function array_key_exists;
use function chr;
use function iconv;
use function ksort;
use function ord;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function usort;

/**
 * Assembles FlashPix extension streams from FPXR APP2 segments.
 *
 * EXIF 3.0 §4.7.3.1 requires APP2 ordering as Contents List first, then Stream Data.
 * EXIF 3.0 §4.7.3.4 and §4.7.3.5 define field-level structures for both segment bodies.
 */
final class FlashPixStreamAssembler implements SegmentAssemblerInterface
{
    private const int FLASHPIX_STORAGE_ENTITY_SIZE = 0xFFFFFFFF;

    /** @var array<int, array{size:int, defaultByte:int, isStorage:bool}> */
    private array $contents = [];

    /** @var array<int, list<array{offset:int, data:string}>> */
    private array $chunks = [];

    /** @var array<int, list<array{start:int, end:int}>> */
    private array $ranges = [];

    /** @var array<int, int> */
    private array $sequenceExpectedCount = [];

    /** @var array<int, array<int, bool>> */
    private array $sequenceSeen = [];

    /** @var array<int, int> */
    private array $sequenceFirstOffset = [];

    private bool $contentsSeen = false;

    private ?int $lastStreamIndex = null;

    /** @var array<int, string> */
    private array $streams = [];

    private int $cumulativeStreamSize = 0;

    /**
     * @param int $maxContentEntries    Maximum allowed FlashPix contents-list entries.
     * @param int $maxStreamSize        Maximum allowed FlashPix stream size per entry in bytes.
     * @param int $maxFlashPixTotalSize Maximum cumulative FlashPix stream size in bytes across all entries.
     */
    public function __construct(
        private readonly int $maxContentEntries,
        private readonly int $maxStreamSize,
        private readonly int $maxFlashPixTotalSize = 8_388_608,
    ) {
    }

    /**
     * Processes one FPXR APP2 segment.
     *
     * EXIF 3.0 §4.7.3.1 requires APP2 ordering as Contents List first, then Stream Data.
     * EXIF 3.0 §4.7.3.4 and §4.7.3.5 define field-level structures for both segment bodies.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    public function handleSegment(string $payload, int $offset): void
    {
        $body = $this->extractBody($payload, $offset);

        if (!$this->contentsSeen) {
            $this->parseContentsList($body, $offset);
            $this->contentsSeen = true;

            return;
        }

        $this->parseStreamData($body, $offset);
    }

    /**
     * Materialises validated FlashPix stream chunks into full stream byte strings.
     *
     * Gaps in stream data are filled with the declared entry default byte
     * (EXIF 3.0 §4.7.3.4 / §4.7.3.5).
     */
    public function finalise(): void
    {
        $this->streams = [];

        foreach ($this->sequenceExpectedCount as $index => $sequenceCount) {
            $seen = $this->sequenceSeen[$index] ?? [];

            for ($expected = 1; $expected <= $sequenceCount; ++$expected) {
                if (!array_key_exists($expected, $seen)) {
                    $firstOffset = $this->sequenceFirstOffset[$index] ?? 0;

                    throw new ParseError(
                        sprintf(
                            'FlashPix stream entry %d is missing sequence number %d of %d (first seen at offset %d)',
                            $index,
                            $expected,
                            $sequenceCount,
                            $firstOffset,
                        ),
                        1483,
                    );
                }
            }
        }

        $aggregateSize = 0;

        foreach ($this->contents as $index => $entry) {
            if ($entry['isStorage']) {
                continue;
            }

            if (!array_key_exists($index, $this->chunks)) {
                continue;
            }

            $chunks = $this->chunks[$index];
            if ($chunks === []) {
                continue;
            }

            $assembledSize = $entry['size'];
            $aggregateSize += $assembledSize;

            if ($aggregateSize > $this->maxFlashPixTotalSize) {
                throw new ParseError(
                    sprintf(
                        'FlashPix aggregate assembled stream size %d exceeds limit %d',
                        $aggregateSize,
                        $this->maxFlashPixTotalSize,
                    ),
                    1962,
                );
            }

            usort(
                $chunks,
                static fn (array $left, array $right): int => $left['offset'] <=> $right['offset'],
            );

            $assembled = '';
            $cursor    = 0;
            $fillByte  = chr($entry['defaultByte']);

            foreach ($chunks as $chunk) {
                if ($chunk['offset'] > $cursor) {
                    $assembled .= str_repeat($fillByte, $chunk['offset'] - $cursor);
                }

                $assembled .= $chunk['data'];
                $cursor = $chunk['offset'] + strlen($chunk['data']);
            }

            if ($cursor < $entry['size']) {
                $assembled .= str_repeat($fillByte, $entry['size'] - $cursor);
            }

            $this->streams[$index] = $assembled;
        }

        if ($this->streams !== []) {
            ksort($this->streams);
        }
    }

    /**
     * Returns the assembled FlashPix streams keyed by contents-list index.
     *
     * @return array<int, string>
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /**
     * Extracts the FlashPix payload body after the FPXR signature.
     *
     * EXIF 3.0 §4.7.3.3 requires:
     * - FPXR signature
     * - NUL byte (00h)
     * - version byte (currently 00h)
     *
     * @param string $payload Raw APP2 payload with FPXR prefix.
     * @param int    $offset  Marker offset used for diagnostics.
     */
    private function extractBody(string $payload, int $offset): string
    {
        $signatureLength = 4; // strlen('FPXR')
        $payloadLength   = strlen($payload);

        if ($payloadLength < $signatureLength + 2) {
            throw new ParseError(sprintf('FlashPix segment at offset %d is too short', $offset), 1281);
        }

        if ($payload[$signatureLength] !== "\x00") {
            throw new ParseError(sprintf('FlashPix segment at offset %d has invalid FPXR ID header', $offset), 1324);
        }

        $version = ord($payload[$signatureLength + 1]);
        if ($version !== 0) {
            throw new ParseError(
                sprintf(
                    'FlashPix segment at offset %d has unsupported FPXR version %d',
                    $offset,
                    $version,
                ),
                1325,
            );
        }

        return substr($payload, $signatureLength + 2);
    }

    /**
     * Parses the first FPXR APP2 body as a Contents List segment.
     *
     * EXIF 3.0 §4.7.3.4:
     * - first two bytes: entry count
     * - each entry: entity size (BE), default byte, UTF-16LE NUL-terminated name
     * - storage entries (entity size 0xFFFFFFFF) include a 16-byte ClassID
     *
     * @param string $body   FPXR segment body without signature.
     * @param int    $offset Marker offset used for diagnostics.
     */
    private function parseContentsList(string $body, int $offset): void
    {
        PayloadGuard::ensureMinimumLength($body, 2, sprintf('FlashPix contents list at offset %d', $offset), 1282);

        $entryCount = (ord($body[0]) << 8) | ord($body[1]);

        if ($entryCount > $this->maxContentEntries) {
            throw new ParseError(
                sprintf(
                    'FlashPix contents list at offset %d has too many entries (%d)',
                    $offset,
                    $entryCount,
                ),
                1306,
            );
        }

        $cursor         = 2;
        $length         = strlen($body);
        $this->contents = [];

        for ($index = 0; $index < $entryCount; ++$index) {
            if (($length - $cursor) < 5) {
                throw new ParseError(
                    sprintf(
                        'FlashPix contents entry %d at offset %d is truncated',
                        $index,
                        $offset,
                    ),
                    1307,
                );
            }

            $entitySize = (ord($body[$cursor]) << 24)
                | (ord($body[$cursor + 1]) << 16)
                | (ord($body[$cursor + 2]) << 8)
                | ord($body[$cursor + 3]);
            $defaultByte = ord($body[$cursor + 4]);
            $cursor += 5;

            [, $cursor] = $this->parseName($body, $cursor, $offset, $index);

            $isStorage = $entitySize === self::FLASHPIX_STORAGE_ENTITY_SIZE;
            if (!$isStorage && $entitySize > $this->maxStreamSize) {
                throw new ParseError(
                    sprintf(
                        'FlashPix stream entry %d at offset %d exceeds maximum size',
                        $index,
                        $offset,
                    ),
                    1310,
                );
            }

            if ($isStorage) {
                if (($length - $cursor) < 16) {
                    throw new ParseError(
                        sprintf(
                            'FlashPix storage entry %d at offset %d is missing ClassID',
                            $index,
                            $offset,
                        ),
                        1311,
                    );
                }

                $cursor += 16;
            }

            $this->contents[$index] = [
                'size'        => $entitySize,
                'defaultByte' => $defaultByte,
                'isStorage'   => $isStorage,
            ];
        }

        // Tolerate trailing bytes per Postel's Law — the parsed entries are complete.
    }

    /**
     * Parses one UTF-16LE NUL-terminated FlashPix contents-list name.
     *
     * @param string $body   FPXR contents-list body.
     * @param int    $cursor Current parsing offset in $body.
     * @param int    $offset APP2 marker offset for diagnostics.
     * @param int    $index  Contents-list entry index.
     *
     * @return array{0:string, 1:int}
     */
    private function parseName(string $body, int $cursor, int $offset, int $index): array
    {
        $length    = strlen($body);
        $nameBytes = '';

        while (true) {
            if (($length - $cursor) < 2) {
                throw new ParseError(
                    sprintf(
                        'FlashPix contents entry %d at offset %d has unterminated name',
                        $index,
                        $offset,
                    ),
                    1313,
                );
            }

            $codeUnit = substr($body, $cursor, 2);
            $cursor += 2;

            if ($codeUnit === "\x00\x00") {
                break;
            }

            $nameBytes .= $codeUnit;
        }

        if ($nameBytes === '') {
            return ['', $cursor];
        }

        $decoded = iconv('UTF-16LE', 'UTF-8', $nameBytes);
        if ($decoded === false) {
            return ['', $cursor];
        }

        return [$decoded, $cursor];
    }

    /**
     * Parses one FPXR Stream Data segment and records validated stream chunks.
     *
     * EXIF 3.0 §4.7.3.5:
     * - index into contents list (0-based)
     * - sequence number / sequence count for segment assembly
     * - offset into full stream
     * - remaining bytes are stream data
     *
     * @param string $body   FPXR segment body without signature.
     * @param int    $offset Marker offset used for diagnostics.
     */
    private function parseStreamData(string $body, int $offset): void
    {
        if (!$this->contentsSeen) {
            throw new ParseError(sprintf('FlashPix stream data at offset %d appears before contents list', $offset), 1316);
        }

        PayloadGuard::ensureMinimumLength($body, 10, sprintf('FlashPix stream data at offset %d', $offset), 1317);

        $index          = (ord($body[0]) << 8) | ord($body[1]);
        $sequenceNumber = (ord($body[2]) << 8) | ord($body[3]);
        $sequenceCount  = (ord($body[4]) << 8) | ord($body[5]);
        $streamOffset   = (ord($body[6]) << 24)
            | (ord($body[7]) << 16)
            | (ord($body[8]) << 8)
            | ord($body[9]);
        $data = substr($body, 10);

        if (!array_key_exists($index, $this->contents)) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d has invalid contents-list index %d',
                    $offset,
                    $index,
                ),
                1319,
            );
        }

        if (($sequenceCount === 0) || ($sequenceNumber === 0) || ($sequenceNumber > $sequenceCount)) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d has invalid sequence metadata (%d/%d) for entry %d',
                    $offset,
                    $sequenceNumber,
                    $sequenceCount,
                    $index,
                ),
                1480,
            );
        }

        if (!array_key_exists($index, $this->sequenceExpectedCount)) {
            $this->sequenceExpectedCount[$index] = $sequenceCount;
            $this->sequenceSeen[$index]          = [];
            $this->sequenceFirstOffset[$index]   = $offset;
        } elseif ($this->sequenceExpectedCount[$index] !== $sequenceCount) {
            $firstOffset = $this->sequenceFirstOffset[$index] ?? $offset;

            throw new ParseError(
                sprintf(
                    'FlashPix stream entry %d has inconsistent sequence count %d at offset %d (expected %d from offset %d)',
                    $index,
                    $sequenceCount,
                    $offset,
                    $this->sequenceExpectedCount[$index],
                    $firstOffset,
                ),
                1481,
            );
        }

        if (array_key_exists($sequenceNumber, $this->sequenceSeen[$index])) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream entry %d has duplicate sequence number %d at offset %d',
                    $index,
                    $sequenceNumber,
                    $offset,
                ),
                1482,
            );
        }

        $this->sequenceSeen[$index][$sequenceNumber] = true;

        $entry = $this->contents[$index];
        if ($entry['isStorage']) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d references storage entry %d',
                    $offset,
                    $index,
                ),
                1320,
            );
        }

        if (
            $streamOffset > $entry['size']
            || ($streamOffset + strlen($data)) > $entry['size']
        ) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d exceeds declared stream size for entry %d',
                    $offset,
                    $index,
                ),
                1321,
            );
        }

        if (($this->lastStreamIndex !== null) && ($index < $this->lastStreamIndex)) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d breaks contents-list order',
                    $offset,
                ),
                1322,
            );
        }

        $this->lastStreamIndex = $index;

        $chunkLength = strlen($data);
        if ($chunkLength === 0) {
            return;
        }

        $newCumulativeSize = $this->cumulativeStreamSize + $chunkLength;

        if ($newCumulativeSize > $this->maxFlashPixTotalSize) {
            throw new ParseError(
                sprintf(
                    'FlashPix cumulative stream size %d exceeds limit %d at offset %d',
                    $newCumulativeSize,
                    $this->maxFlashPixTotalSize,
                    $offset,
                ),
                1948,
            );
        }

        $this->cumulativeStreamSize = $newCumulativeSize;

        $start = $streamOffset;
        $end   = $streamOffset + $chunkLength;

        if (!array_key_exists($index, $this->ranges)) {
            $this->ranges[$index] = [];
        }

        foreach ($this->ranges[$index] as $range) {
            if (($start < $range['end']) && ($end > $range['start'])) {
                throw new ParseError(
                    sprintf(
                        'FlashPix stream data at offset %d overlaps existing data for entry %d',
                        $offset,
                        $index,
                    ),
                    1323,
                );
            }
        }

        $this->ranges[$index][] = ['start' => $start, 'end' => $end];

        if (!array_key_exists($index, $this->chunks)) {
            $this->chunks[$index] = [];
        }

        $this->chunks[$index][] = ['offset' => $start, 'data' => $data];
    }
}
