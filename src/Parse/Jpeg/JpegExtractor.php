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

/**
 * Parses JPEG streams to extract metadata-bearing APP segments.
 */
final class JpegExtractor
{
    private const MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB payload limit
    private const EXIF_SIGNATURE       = "Exif\0\0";
    private const XMP_SIGNATURE        = "http://ns.adobe.com/xap/1.0/\0";
    private const ICC_SIGNATURE        = "ICC_PROFILE\0";
    private const IPTC_SIGNATURE       = "Photoshop 3.0\0";
    private const MARKER_SOS           = 0xDA;
    private const MARKER_EOI           = 0xD9;

    private bool $parsed = false;
    /** @var list<string> */
    private array $exifBlobs = [];
    /** @var list<string> */
    private array $xmpPackets = [];
    /** @var list<string> */
    private array $iccSegments = [];
    /** @var array<int, string> */
    private array $iccSequence     = [];
    private ?int $iccExpectedCount = null;
    private ?string $iccProfile    = null;
    /** @var list<string> */
    private array $iptcPayloads = [];

    /**
     * Initialises the extractor with a seekable stream.
     *
     * @param Stream $s Stream representing the JPEG binary.
     */
    public function __construct(private readonly Stream $s)
    {
    }

    /**
     * Returns all discovered EXIF payloads in the order they appeared.
     *
     * @return list<string>
     */
    public function extractExifBlobs(): array
    {
        $this->parseIfNeeded();

        return $this->exifBlobs;
    }

    /**
     * Returns all discovered XMP packets in the order they appeared.
     *
     * @return list<string>
     */
    public function extractXmpPackets(): array
    {
        $this->parseIfNeeded();

        return $this->xmpPackets;
    }

    /**
     * Returns the merged ICC profile when complete metadata segments were found.
     *
     * @return string|null
     */
    public function getIccProfile(): ?string
    {
        $this->parseIfNeeded();

        return $this->iccProfile;
    }

    /**
     * Returns all ICC profile segments in the order encountered.
     *
     * @return list<string>
     */
    public function getIccSegments(): array
    {
        $this->parseIfNeeded();

        return $this->iccSegments;
    }

    /**
     * Returns all IPTC payloads captured from APP13 segments.
     *
     * @return list<string>
     */
    public function getIptcPayloads(): array
    {
        $this->parseIfNeeded();

        return $this->iptcPayloads;
    }

    /**
     * Lazily scans the JPEG structure the first time metadata is requested.
     */
    private function parseIfNeeded(): void
    {
        if ($this->parsed) {
            return;
        }

        $this->s->seek(0);
        if ($this->s->read(2) !== "\xFF\xD8") {
            throw new ParseError('Not a JPEG (missing SOI marker)');
        }

        $this->exifBlobs        = [];
        $this->xmpPackets       = [];
        $this->iccSegments      = [];
        $this->iccSequence      = [];
        $this->iccExpectedCount = null;
        $this->iccProfile       = null;
        $this->iptcPayloads     = [];

        while (true) {
            [$marker, $offset] = $this->nextMarkerWithOffset();

            if ($marker === self::MARKER_EOI) {
                break;
            }

            if ($marker === self::MARKER_SOS) {
                break; // stop scanning metadata when scan data begins
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue; // markers without payload
            }

            $isAppSegment  = $marker >= 0xE0 && $marker <= 0xEF;
            $segmentLength = $this->readSegmentLength($marker, $offset, $isAppSegment);
            $payloadLength = $segmentLength - 2;
            $payload       = $this->readSegmentPayload($marker, $offset, $payloadLength);

            if ($marker === 0xE1) {
                $this->handleApp1($payload);
            } elseif ($marker === 0xE2) {
                $this->handleApp2($payload, $offset);
            } elseif ($marker === 0xED) {
                $this->handleApp13($payload);
            }
        }

        if ($this->iccExpectedCount !== null && $this->iccExpectedCount > 0) {
            if (count($this->iccSequence) === $this->iccExpectedCount) {
                $expectedSequence = range(1, $this->iccExpectedCount);
                $presentSequence  = array_keys($this->iccSequence);
                sort($presentSequence);

                if ($presentSequence === $expectedSequence) {
                    ksort($this->iccSequence);
                    $this->iccProfile = implode('', $this->iccSequence);
                }
            }
        }

        $this->parsed = true;
    }

    /**
     * Finds the next JPEG marker and returns its code and byte offset.
     *
     * @return array{0: int, 1: int}
     */
    private function nextMarkerWithOffset(): array
    {
        while (true) {
            $byte = $this->s->read(1);
            while ($byte !== "\xFF") {
                $byte = $this->s->read(1);
            }

            $markerOffset = $this->s->tell() - 1;

            do {
                $code = $this->s->read(1);
            } while ($code === "\xFF");

            if ($code === "\x00") {
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
     *
     * @return int
     */
    private function readSegmentLength(int $marker, int $offset, bool $enforceMax): int
    {
        $length = $this->s->readU16BE();

        if ($length < 2) {
            throw new ParseError(
                sprintf('Segment length %d for marker 0x%02X at offset %d is invalid', $length, $marker, $offset)
            );
        }

        if ($enforceMax) {
            $payloadLength = $length - 2;
            if ($payloadLength > self::MAX_APP_SEGMENT_SIZE) {
                throw new ParseError(
                    sprintf(
                        'APP segment 0x%02X at offset %d exceeds maximum payload of %d bytes',
                        $marker,
                        $offset,
                        self::MAX_APP_SEGMENT_SIZE,
                    )
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
     *
     * @return string
     */
    private function readSegmentPayload(int $marker, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        try {
            return $this->s->read($length);
        } catch (BoundsError $exception) {
            throw new ParseError(
                sprintf('Truncated segment for marker 0x%02X at offset %d', $marker, $offset),
                0,
                $exception
            );
        }
    }

    /**
     * Processes APP1 payloads for EXIF and XMP signatures.
     *
     * @param string $payload Raw APP1 payload including leading signature.
     */
    private function handleApp1(string $payload): void
    {
        if (str_starts_with($payload, self::EXIF_SIGNATURE)) {
            $this->exifBlobs[] = substr($payload, strlen(self::EXIF_SIGNATURE));

            return;
        }

        if (str_starts_with($payload, self::XMP_SIGNATURE)) {
            $this->xmpPackets[] = substr($payload, strlen(self::XMP_SIGNATURE));
        }
    }

    /**
     * Processes APP2 payloads containing ICC profile segments.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleApp2(string $payload, int $offset): void
    {
        if (!str_starts_with($payload, self::ICC_SIGNATURE)) {
            return;
        }

        $signatureLength = strlen(self::ICC_SIGNATURE);
        if (strlen($payload) < $signatureLength + 2) {
            throw new ParseError(sprintf('ICC segment at offset %d is too short', $offset));
        }

        $sequenceNumber = ord($payload[$signatureLength]);
        $sequenceCount  = ord($payload[$signatureLength + 1]);
        $iccData        = substr($payload, $signatureLength + 2);

        $this->iccSegments[] = $payload;

        if ($sequenceNumber === 0 || $sequenceCount === 0 || $sequenceNumber > $sequenceCount) {
            $this->iccExpectedCount = 0;

            return;
        }

        if ($this->iccExpectedCount === null) {
            $this->iccExpectedCount = $sequenceCount;
        } elseif ($this->iccExpectedCount !== $sequenceCount) {
            $this->iccExpectedCount = 0;

            return;
        }

        if (!array_key_exists($sequenceNumber, $this->iccSequence)) {
            $this->iccSequence[$sequenceNumber] = $iccData;
        }
    }

    /**
     * Processes APP13 payloads to capture IPTC data blocks.
     *
     * @param string $payload Raw APP13 payload including leading signature.
     */
    private function handleApp13(string $payload): void
    {
        if (str_starts_with($payload, self::IPTC_SIGNATURE)) {
            $this->iptcPayloads[] = $payload;
        }
    }
}
