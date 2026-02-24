<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use Closure;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function array_key_exists;
use function array_keys;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;
use function usort;

/**
 * Reassembles ExtendedXMP APP1 chunks and merges them with referenced base packets.
 *
 * Adobe XMP Storage in Files defines the JPEG APP1 extension container as:
 * signature + GUID + full-length + chunk-offset + chunk bytes.
 */
final class ExtendedXmpAssembler implements SegmentAssemblerInterface
{
    /**
     * Signature identifying ExtendedXMP APP1 segments.
     */
    public const string SIGNATURE = "http://ns.adobe.com/xmp/extension/\0";

    /**
     * Byte length of the full-length and chunk-offset header fields combined.
     */
    private const int HEADER_LENGTH = 8;

    /** @var list<array{packet:string, guid:string, offset:int}> */
    private array $basePackets = [];

    /** @var array<string, list<array{offset:int, length:int, data:string, segmentOffset:int}>> */
    private array $chunks = [];

    /** @var array<string, int> */
    private array $totalLength = [];

    /** @var array<string, int> */
    private array $firstOffset = [];

    /**
     * @param int                   $guidLength      ExtendedXMP GUID byte length.
     * @param Closure(string): void $appendXmpPacket Callback for appending resolved XMP packets.
     */
    public function __construct(
        private readonly int $guidLength,
        private readonly Closure $appendXmpPacket,
    ) {
    }

    /**
     * Processes one ExtendedXMP APP1 chunk.
     *
     * Adobe XMP Storage in Files defines the JPEG APP1 extension container as:
     * signature + GUID + full-length + chunk-offset + chunk bytes.
     *
     * @param string $payload Raw APP1 payload containing extended XMP header fields.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    public function handleSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::SIGNATURE);
        $guidLength      = $this->guidLength;
        $minimumLength   = $signatureLength + $guidLength + self::HEADER_LENGTH;

        if (strlen($payload) < $minimumLength) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d is too short', $offset),
                1470,
            );
        }

        $guidRaw     = substr($payload, $signatureLength, $guidLength);
        $guidPattern = '/^[0-9A-Fa-f]{' . $guidLength . '}$/';

        if (preg_match($guidPattern, $guidRaw) !== 1) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has invalid GUID', $offset),
                1471,
            );
        }

        $guid = strtoupper($guidRaw);

        $lengthOffset  = $signatureLength + $guidLength;
        $totalLength   = Unpack::int('N', substr($payload, $lengthOffset, 4), 'ExtendedXMP full length');
        $chunkOffset   = Unpack::int('N', substr($payload, $lengthOffset + 4, 4), 'ExtendedXMP chunk offset');
        $extendedChunk = substr($payload, $lengthOffset + self::HEADER_LENGTH);

        if ($totalLength <= 0) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has non-positive full length %d', $offset, $totalLength),
                1472,
            );
        }

        $chunkLength = strlen($extendedChunk);

        if ($chunkLength === 0) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has empty chunk payload', $offset),
                1473,
            );
        }

        if ($chunkOffset > $totalLength) {
            throw new ParseError(
                sprintf(
                    'ExtendedXMP APP1 segment at offset %d has chunk offset %d outside full length %d',
                    $offset,
                    $chunkOffset,
                    $totalLength,
                ),
                1473,
            );
        }

        if ($chunkLength > $totalLength || $chunkOffset > ($totalLength - $chunkLength)) {
            throw new ParseError(
                sprintf(
                    'ExtendedXMP APP1 segment at offset %d has out-of-range chunk [%d,%d) for full length %d',
                    $offset,
                    $chunkOffset,
                    $chunkOffset + $chunkLength,
                    $totalLength,
                ),
                1473,
            );
        }

        if (!array_key_exists($guid, $this->totalLength)) {
            $this->totalLength[$guid] = $totalLength;
            $this->firstOffset[$guid] = $offset;
            $this->chunks[$guid]      = [];
        } elseif ($this->totalLength[$guid] !== $totalLength) {
            $firstOffset = $this->firstOffset[$guid] ?? $offset;

            throw new ParseError(
                sprintf(
                    'ExtendedXMP GUID %s has inconsistent full length %d at offset %d (first seen %d at offset %d)',
                    $guid,
                    $totalLength,
                    $offset,
                    $this->totalLength[$guid],
                    $firstOffset,
                ),
                1474,
            );
        }

        $this->chunks[$guid][] = [
            'offset'        => $chunkOffset,
            'length'        => $chunkLength,
            'data'          => $extendedChunk,
            'segmentOffset' => $offset,
        ];
    }

    /**
     * Extracts xmpNote:HasExtendedXMP GUID references from base XMP packets.
     *
     * @param string $packet Raw base XMP packet.
     * @param int    $offset APP1 marker offset for diagnostics.
     *
     * @return string|null Uppercase GUID when present, null otherwise.
     */
    public function extractGuidFromPacket(string $packet, int $offset): ?string
    {
        if (!str_contains($packet, 'xmpNote:HasExtendedXMP')) {
            return null;
        }

        $attributeMatch = preg_match('/xmpNote:HasExtendedXMP\s*=\s*["\']\s*([0-9A-Fa-f]{32})\s*["\']/', $packet, $matches);

        if ($attributeMatch === 1) {
            return strtoupper($matches[1]);
        }

        $elementMatch = preg_match('/<xmpNote:HasExtendedXMP>\s*([0-9A-Fa-f]{32})\s*<\/xmpNote:HasExtendedXMP>/', $packet, $matches);

        if ($elementMatch === 1) {
            return strtoupper($matches[1]);
        }

        throw new ParseError(
            sprintf('XMP packet at offset %d has invalid xmpNote:HasExtendedXMP GUID', $offset),
            1475,
        );
    }

    /**
     * Registers a base XMP packet with an ExtendedXMP GUID reference.
     *
     * @param string $packet Raw base XMP packet body.
     * @param string $guid   Uppercase ExtendedXMP GUID.
     * @param int    $offset APP1 marker offset for diagnostics.
     */
    public function addBasePacket(string $packet, string $guid, int $offset): void
    {
        $this->basePackets[] = [
            'packet' => $packet,
            'guid'   => $guid,
            'offset' => $offset,
        ];
    }

    /**
     * Reassembles ExtendedXMP chunks and merges them with referenced base packets.
     */
    public function finalise(): void
    {
        if ($this->basePackets === []) {
            return;
        }

        $requiredGuids = [];
        foreach ($this->basePackets as $basePacket) {
            $requiredGuids[$basePacket['guid']] = true;
        }

        foreach (array_keys($this->chunks) as $guid) {
            if (!array_key_exists($guid, $requiredGuids)) {
                $offset = $this->firstOffset[$guid] ?? 0;

                throw new ParseError(
                    sprintf(
                        'ExtendedXMP GUID %s from APP1 extension chunk at offset %d has no matching xmpNote:HasExtendedXMP base packet',
                        $guid,
                        $offset,
                    ),
                    1476,
                );
            }
        }

        /** @var array<string, string> $assembledPayloads */
        $assembledPayloads = [];
        foreach ($this->basePackets as $basePacket) {
            $guid = $basePacket['guid'];

            if (!array_key_exists($guid, $this->chunks)) {
                throw new ParseError(
                    sprintf(
                        'XMP packet at offset %d references ExtendedXMP GUID %s but matching extension chunks are missing',
                        $basePacket['offset'],
                        $guid,
                    ),
                    1477,
                );
            }

            if (!array_key_exists($guid, $assembledPayloads)) {
                $assembledPayloads[$guid] = $this->assemblePayload($guid, $basePacket['offset']);
            }

            ($this->appendXmpPacket)($basePacket['packet'] . $assembledPayloads[$guid]);
        }
    }

    /**
     * Validates and concatenates all ExtendedXMP chunks for one GUID.
     *
     * @param string $guid       ExtendedXMP GUID.
     * @param int    $baseOffset Base APP1 offset for diagnostics.
     */
    private function assemblePayload(string $guid, int $baseOffset): string
    {
        $chunks      = $this->chunks[$guid] ?? [];
        $totalLength = $this->totalLength[$guid] ?? 0;

        if (($chunks === []) || ($totalLength <= 0)) {
            throw new ParseError(
                sprintf('ExtendedXMP GUID %s has no decodable extension chunks', $guid),
                1477,
            );
        }

        usort(
            $chunks,
            static fn (array $left, array $right): int => $left['offset'] <=> $right['offset'],
        );

        $cursor    = 0;
        $assembled = '';

        foreach ($chunks as $chunk) {
            if ($chunk['offset'] > $cursor) {
                throw new ParseError(
                    sprintf(
                        'ExtendedXMP GUID %s is missing bytes at offset %d (next chunk starts at %d)',
                        $guid,
                        $cursor,
                        $chunk['offset'],
                    ),
                    1478,
                );
            }

            if ($chunk['offset'] < $cursor) {
                throw new ParseError(
                    sprintf(
                        'ExtendedXMP GUID %s has overlapping chunks around offset %d (segment offset %d)',
                        $guid,
                        $chunk['offset'],
                        $chunk['segmentOffset'],
                    ),
                    1479,
                );
            }

            $assembled .= $chunk['data'];
            $cursor += $chunk['length'];
        }

        if ($cursor !== $totalLength) {
            throw new ParseError(
                sprintf(
                    'ExtendedXMP GUID %s is incomplete: expected %d bytes but assembled %d bytes (base offset %d)',
                    $guid,
                    $totalLength,
                    $cursor,
                    $baseOffset,
                ),
                1478,
            );
        }

        return $assembled;
    }
}
