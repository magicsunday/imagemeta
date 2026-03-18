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
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function array_key_exists;
use function array_keys;
use function implode;
use function ksort;
use function max;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Reassembles APP11 JUMBF transport streams and extracts XMP packets.
 *
 * EXIF 3.0 §4.7.5.3 defines the APP11 transport wrapper for JUMBF
 * superboxes carrying annotation metadata. Supported XML/XMP payloads
 * are surfaced through a caller-supplied packet collector.
 */
final class JumbfTransportParser implements SegmentAssemblerInterface
{
    private const int TRANSPORT_HEADER_LENGTH = 10;

    private const int MAX_SEQUENCE_NUMBER = 65_535;

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    /** @var array<int, array<int, string>> */
    private array $sequence = [];

    /** @var array<int, string> */
    private array $identifier = [];

    /** @var array<int, int> */
    private array $firstOffset = [];

    /**
     * @param Closure(string): void $appendXmpPacket
     */
    public function __construct(private readonly Closure $appendXmpPacket)
    {
    }

    /**
     * Processes one APP11 payload carrying JUMBF box-structured metadata.
     *
     * EXIF 3.0 §4.7.5.3 defines the APP11 transport wrapper and stores JUMBF
     * superboxes for annotation metadata. Supported XML/XMP payloads are
     * surfaced through the existing XMP packet collection.
     *
     * @param string $payload Raw APP11 payload.
     * @param int    $offset  Offset in the stream where the marker begins.
     *
     * @throws ParseError When the transport header is invalid or instance metadata is inconsistent.
     */
    public function handleSegment(string $payload, int $offset): void
    {
        if (!str_starts_with($payload, 'JP')) {
            return;
        }

        $header         = $this->parseTransportHeader($payload, $offset);
        $identifier     = $header['identifier'];
        $instanceNumber = $header['instance'];
        $sequenceNumber = $header['sequence'];
        $transportData  = $header['data'];

        if (!array_key_exists($instanceNumber, $this->identifier)) {
            $this->identifier[$instanceNumber]  = $identifier;
            $this->firstOffset[$instanceNumber] = $offset;
        } elseif ($this->identifier[$instanceNumber] !== $identifier) {
            throw new ParseError(
                sprintf(
                    'APP11 segment at offset %d has inconsistent instance metadata for instance %d',
                    $offset,
                    $instanceNumber,
                ),
                1337,
            );
        }

        if (!array_key_exists($instanceNumber, $this->sequence)) {
            $this->sequence[$instanceNumber] = [];
        }

        if (array_key_exists($sequenceNumber, $this->sequence[$instanceNumber])) {
            throw new ParseError(
                sprintf(
                    'APP11 segment at offset %d has duplicate sequence number %d for instance %d',
                    $offset,
                    $sequenceNumber,
                    $instanceNumber,
                ),
                1338,
            );
        }

        $this->sequence[$instanceNumber][$sequenceNumber] = $transportData;
    }

    /**
     * Finalises APP11 transport streams by validating and reassembling chunks.
     *
     * EXIF 3.0 §4.7.5.1 and §4.7.5.3 define APP11 sequence metadata for
     * marker-segment merging when logically identical JUMBF data is split.
     *
     * @throws ParseError When sequence numbers are missing or JUMBF boxes are malformed.
     */
    public function finalise(): void
    {
        foreach ($this->sequence as $instanceNumber => $sequenceChunks) {
            if ($sequenceChunks === []) {
                continue;
            }

            $segmentOffset = $this->firstOffset[$instanceNumber] ?? 0;
            $maxSequence   = max(array_keys($sequenceChunks));

            for ($expectedSequence = 1; $expectedSequence <= $maxSequence; ++$expectedSequence) {
                if (!array_key_exists($expectedSequence, $sequenceChunks)) {
                    throw new ParseError(
                        sprintf(
                            'APP11 segment sequence is missing sequence number %d for instance %d (at offset %d)',
                            $expectedSequence,
                            $instanceNumber,
                            $segmentOffset,
                        ),
                        1339,
                    );
                }
            }

            ksort($sequenceChunks);
            $transportPayload = implode('', $sequenceChunks);
            $jumbfSuperbox    = $this->extractJumbfSuperbox($transportPayload, $segmentOffset);
            $this->collectXmlPacketsFromBoxes($jumbfSuperbox, $segmentOffset);
        }
    }

    /**
     * Parses the APP11 transport header and returns sequence metadata.
     *
     * EXIF 3.0 §4.7.5.3 defines the APP11 transport wrapper as identifier, box
     * instance number, packet sequence number, and payload bytes.
     *
     * @param string $payload       Raw APP11 payload bytes.
     * @param int    $segmentOffset APP11 marker offset for diagnostics.
     *
     * @return array{identifier:string, instance:int, sequence:int, data:string}
     */
    private function parseTransportHeader(string $payload, int $segmentOffset): array
    {
        PayloadGuard::ensureMinimumLength($payload, self::TRANSPORT_HEADER_LENGTH, sprintf('APP11 segment at offset %d', $segmentOffset), 1331);

        // EXIF 3.0 §4.7.5.3 APP11 transport header layout:
        // Bytes 0–3: CI (Common Identifier, 4 bytes)
        // Bytes 4–5: En (Instance Number, uint16)
        // Bytes 6–9: Z  (Sequence Number, uint32)
        $identifier = substr($payload, 0, 4);

        $instanceNumber = Unpack::int('n', substr($payload, 4, 2), 'APP11 instance number');

        if ($instanceNumber === 0) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has out-of-range instance number %d', $segmentOffset, $instanceNumber),
                1335,
            );
        }

        $sequenceNumber = Unpack::int('N', substr($payload, 6, 4), 'APP11 sequence number');

        if (($sequenceNumber === 0) || ($sequenceNumber > self::MAX_SEQUENCE_NUMBER)) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has out-of-range sequence number %d', $segmentOffset, $sequenceNumber),
                1336,
            );
        }

        return [
            'identifier' => $identifier,
            'instance'   => $instanceNumber,
            'sequence'   => $sequenceNumber,
            'data'       => substr($payload, self::TRANSPORT_HEADER_LENGTH),
        ];
    }

    /**
     * Extracts the first valid JUMBF superbox from APP11 transport payload data.
     *
     * @param string $payload       Reassembled APP11 transport payload bytes.
     * @param int    $segmentOffset APP11 marker offset for diagnostics.
     *
     * @return string Raw bytes of the JUMBF superbox.
     */
    private function extractJumbfSuperbox(string $payload, int $segmentOffset): string
    {
        PayloadGuard::ensureMinimumLength($payload, 12, sprintf('APP11 segment at offset %d', $segmentOffset), 1885);
        $length = strlen($payload);

        for ($offset = 0; $offset + 8 <= $length; ++$offset) {
            if (substr($payload, $offset + 4, 4) !== 'jumb') {
                continue;
            }

            $boxLength = Unpack::int('N', substr($payload, $offset, 4), 'JUMBF superbox size');

            if ($boxLength < 8) {
                throw new ParseError(
                    sprintf('APP11 segment at offset %d has invalid JUMBF box length %d', $segmentOffset, $boxLength),
                    1332,
                );
            }

            if ($offset + $boxLength > $length) {
                throw new ParseError(sprintf('APP11 segment at offset %d has truncated JUMBF box', $segmentOffset), 1900);
            }

            return substr($payload, $offset, $boxLength);
        }

        throw new ParseError(sprintf('APP11 segment at offset %d does not contain a JUMBF superbox', $segmentOffset), 1901);
    }

    /**
     * Traverses JUMBF boxes and collects XML/XMP payloads.
     *
     * @param string $boxStream     Box stream beginning with one or more ISO-BMFF-style boxes.
     * @param int    $segmentOffset APP11 marker offset for diagnostics.
     */
    private function collectXmlPacketsFromBoxes(string $boxStream, int $segmentOffset): void
    {
        $length = strlen($boxStream);
        $offset = 0;

        while ($offset + 8 <= $length) {
            $boxLength = Unpack::int('N', substr($boxStream, $offset, 4), 'JUMBF child box size');

            if ($boxLength < 8) {
                throw new ParseError(
                    sprintf('APP11 segment at offset %d has invalid JUMBF child box length %d', $segmentOffset, $boxLength),
                    1977,
                );
            }

            if ($offset + $boxLength > $length) {
                throw new ParseError(sprintf('APP11 segment at offset %d has truncated JUMBF child box', $segmentOffset), 1887);
            }

            $boxType    = substr($boxStream, $offset + 4, 4);
            $boxPayload = substr($boxStream, $offset + 8, $boxLength - 8);

            if ($boxType === 'jumb') {
                $this->collectXmlPacketsFromBoxes($boxPayload, $segmentOffset);
            } elseif ($boxType === 'xml ' || $boxType === 'bidb') {
                $candidate = $this->extractXmlPacketCandidate($boxPayload);

                if ($candidate !== null) {
                    ($this->appendXmpPacket)($candidate);
                }
            }

            $offset += $boxLength;
        }

        if ($offset !== $length) {
            throw new ParseError(sprintf('APP11 segment at offset %d has trailing JUMBF bytes', $segmentOffset), 1888);
        }
    }

    /**
     * Extracts XML/XMP packet text from a JUMBF payload when recognizable.
     *
     * @param string $payload Raw JUMBF content payload.
     *
     * @return string|null XML/XMP packet text or null when not recognized.
     */
    private function extractXmlPacketCandidate(string $payload): ?string
    {
        if (str_starts_with($payload, self::XMP_SIGNATURE)) {
            return substr($payload, strlen(self::XMP_SIGNATURE));
        }

        if (!str_contains($payload, '<')) {
            return null;
        }

        if (!str_contains($payload, '<?xml') && !str_contains($payload, '<x:xmpmeta') && !str_contains($payload, '<rdf:RDF')) {
            return null;
        }

        $start = strpos($payload, '<');

        if ($start === false) {
            return null;
        }

        $candidate = trim(substr($payload, $start));

        return $candidate !== '' ? $candidate : null;
    }
}
