<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

use function min;
use function ord;
use function sprintf;

/**
 * Low-level marker navigation and segment I/O over a JPEG byte stream.
 *
 * ITU-T T.81 §B.1.1.2 defines the marker segment structure decoded here.
 */
final readonly class JpegMarkerScanner
{
    /**
     * Maximum APP payload length implied by JPEG 16-bit segment length semantics.
     *
     * JPEG segment length includes its own two-byte length field, so payload is
     * bounded to 65535 - 2 = 65533 bytes.
     */
    private const int MAX_JPEG_APP_PAYLOAD_BYTES = 65_533;

    /**
     * @param Stream           $stream Seekable binary stream.
     * @param JpegParserConfig $config Parser limit configuration.
     */
    public function __construct(
        private Stream $stream,
        private JpegParserConfig $config,
    ) {
    }

    /**
     * Finds the next JPEG marker and returns its code and byte offset.
     *
     * @param bool $allowInterveningBytes Whether non-marker bytes may appear before the next marker introducer.
     *
     * @return array{0: int, 1: int}
     */
    public function nextMarkerWithOffset(bool $allowInterveningBytes = true): array
    {
        while (true) {
            $byteOffset = $this->stream->tell();
            $byte       = $this->stream->read(1);

            if ($byte !== "\xFF") {
                if ($allowInterveningBytes) {
                    continue;
                }

                throw new ParseError(
                    sprintf(
                        'Non-marker byte 0x%02X at offset %d is not allowed before SOS marker segments',
                        ord($byte),
                        $byteOffset,
                    ),
                    1505,
                );
            }

            $markerOffset = $byteOffset;

            do {
                $code = $this->stream->read(1);
            } while ($code === "\xFF");

            if ($code === "\x00") {
                if (!$allowInterveningBytes) {
                    throw new ParseError(
                        sprintf(
                            'Marker-stuffing sequence 0xFF00 at offset %d is not allowed before SOS marker segments',
                            $markerOffset,
                        ),
                        1506,
                    );
                }

                continue; // stuffed byte inside scan data
            }

            return [ord($code), $markerOffset];
        }
    }

    /**
     * Reads and validates the length of a marker segment.
     *
     * @param int  $marker     Marker code currently being processed.
     * @param int  $offset     Offset in the stream where the marker begins.
     * @param bool $enforceMax Whether to enforce the APP segment size guard.
     */
    public function readSegmentLength(int $marker, int $offset, bool $enforceMax): int
    {
        $length = $this->stream->readU16BE();

        if ($length < 2) {
            throw new ParseError(
                sprintf('Segment length %d for marker 0x%02X at offset %d is invalid', $length, $marker, $offset),
                1265,
            );
        }

        if ($enforceMax) {
            $payloadLength = $length - 2;
            $maxPayload    = min($this->config->maxAppSegmentSize, self::MAX_JPEG_APP_PAYLOAD_BYTES);

            if ($payloadLength > $maxPayload) {
                throw new ParseError(
                    sprintf(
                        'APP segment 0x%02X at offset %d exceeds maximum payload of %d bytes',
                        $marker,
                        $offset,
                        $maxPayload,
                    ),
                    1266,
                );
            }
        }

        return $length;
    }

    /**
     * Reads the payload of a segment while converting bounds errors to parse errors.
     *
     * @param int $marker Marker code currently being processed.
     * @param int $offset Offset in the stream where the marker begins.
     * @param int $length Number of bytes to read for the payload.
     */
    public function readSegmentPayload(int $marker, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        try {
            return $this->stream->read($length);
        } catch (BoundsError $exception) {
            throw new ParseError(
                sprintf('Truncated segment for marker 0x%02X at offset %d', $marker, $offset),
                1267,
                $exception
            );
        }
    }
}
