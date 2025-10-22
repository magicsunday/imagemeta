<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

final class JpegExtractor
{
    private const EXIF_SIGNATURE = "Exif\0\0";
    private const XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";
    private const MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB

    /** @var list<string> */
    private array $exifBlobs = [];
    /** @var list<string> */
    private array $xmpPackets = [];
    private JpegPayloads $payloads;
    private bool $parsed = false;

    public function __construct(private readonly Stream $s)
    {
        $this->payloads = new JpegPayloads();
    }

    /**
     * @return list<string> TIFF blobs prefixed with the Exif header
     */
    public function extractExifBlobs(): array
    {
        $this->parse();

        return $this->exifBlobs;
    }

    /** @return list<string> XMP packets as XML strings */
    public function extractXmpPackets(): array
    {
        $this->parse();

        return $this->xmpPackets;
    }

    /** @return list<string> */
    public function getIccPayloads(): array
    {
        $this->parse();

        return $this->payloads->getIccPayloads();
    }

    /** @return list<string> */
    public function getIptcPayloads(): array
    {
        $this->parse();

        return $this->payloads->getIptcPayloads();
    }

    private function parse(): void
    {
        if ($this->parsed) {
            return;
        }

        $this->parsed = true;
        $this->exifBlobs = [];
        $this->xmpPackets = [];
        $this->payloads = new JpegPayloads();

        $stream = $this->s;
        $stream->seek(0);

        $this->ensureSoi();

        while (true) {
            $marker = $this->nextMarker();

            if ($marker === 0xD9 || $marker === 0xDA) { // EOI or SOS
                break;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { // TEM / Restart markers
                continue;
            }

            $payloadLength = $this->readSegmentPayloadLength($marker);
            $payload = $this->readSegmentPayload($marker, $payloadLength);

            $this->handleSegment($marker, $payload);
        }
    }

    private function ensureSoi(): void
    {
        try {
            $soi = $this->s->read(2);
        } catch (BoundsError $exception) {
            throw new ParseError('Not a JPEG (truncated before SOI)');
        }

        if ($soi !== "\xFF\xD8") {
            throw new ParseError('Not a JPEG (missing SOI)');
        }
    }

    private function handleSegment(int $marker, string $payload): void
    {
        switch ($marker) {
            case 0xE1:
                $this->handleApp1($payload);
                break;
            case 0xE2:
                $this->payloads->addIccPayload($payload);
                break;
            case 0xED:
                $this->payloads->addIptcPayload($payload);
                break;
        }
    }

    private function handleApp1(string $payload): void
    {
        if (str_starts_with($payload, self::EXIF_SIGNATURE)) {
            $this->appendExif($payload);
            return;
        }

        if (str_starts_with($payload, self::XMP_SIGNATURE)) {
            $this->xmpPackets[] = substr($payload, strlen(self::XMP_SIGNATURE));
        }
    }

    private function appendExif(string $payload): void
    {
        $signatureLength = strlen(self::EXIF_SIGNATURE);
        $body = substr($payload, $signatureLength);

        if ($this->exifBlobs === []) {
            $this->exifBlobs[] = $payload;

            return;
        }

        if ($body !== '' && $this->startsWithTiffHeader($body)) {
            $this->exifBlobs[] = $payload;

            return;
        }

        $this->exifBlobs[0] .= $body;
    }

    private function startsWithTiffHeader(string $body): bool
    {
        return str_starts_with($body, "MM\0*") || str_starts_with($body, "II*\0");
    }

    private function nextMarker(): int
    {
        $stream = $this->s;

        while (true) {
            try {
                $byte = $stream->read(1);
            } catch (BoundsError $exception) {
                throw new ParseError('Unexpected EOF while searching for marker');
            }

            if ($byte !== "\xFF") {
                continue;
            }

            while (true) {
                try {
                    $markerByte = $stream->read(1);
                } catch (BoundsError $exception) {
                    throw new ParseError('Unexpected EOF after marker prefix 0xFF');
                }

                if ($markerByte === "\xFF") {
                    continue;
                }

                if ($markerByte === "\x00") {
                    continue 2;
                }

                return ord($markerByte);
            }
        }
    }

    private function readSegmentPayloadLength(int $marker): int
    {
        try {
            $length = $this->s->readU16BE();
        } catch (BoundsError $exception) {
            throw new ParseError(sprintf('Unexpected EOF reading length for marker 0x%02X', $marker));
        }

        if ($length < 2) {
            throw new ParseError(sprintf('Invalid segment length (%d) for marker 0x%02X', $length, $marker));
        }

        $payloadLength = $length - 2;

        if ($payloadLength > self::MAX_APP_SEGMENT_SIZE) {
            throw new ParseError(sprintf('Segment payload too large (%d bytes) for marker 0x%02X', $payloadLength, $marker));
        }

        return $payloadLength;
    }

    private function readSegmentPayload(int $marker, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        try {
            return $this->s->read($length);
        } catch (BoundsError $exception) {
            throw new ParseError(sprintf('Segment payload truncated for marker 0x%02X (expected %d bytes)', $marker, $length));
        }
    }
}
