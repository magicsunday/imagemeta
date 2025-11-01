<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

use function array_key_exists;
use function ord;
use function str_starts_with;
use function sprintf;
use function intdiv;
use function strlen;
use function substr;
use function unpack;

/**
 * Parses JPEG streams to extract EXIF APP1 payloads alongside basic frame metadata.
 *
 * EXIF 3.0 §4.7.2 mandates that EXIF data inside APP1 markers begins with the
 * "Exif\0\0" preamble followed by the TIFF header described in §4.5. Earlier
 * revisions (EXIF 2.32 §4.7.2 and EXIF 2.1 §2.7.2) defined the same structure.
 */
final class JpegExtractor
{
    private const int MAX_SEGMENT_SIZE = 4_194_304; // 4 MiB payload limit

    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const int MARKER_PREFIX = 0xFF;

    private bool $parsed = false;

    /** @var list<string> */
    private array $exifBlobs = [];

    private ?int $frameBitsPerSample = null;

    private ?int $frameHeight = null;

    private ?int $frameWidth = null;

    /** @var array<int, array{horizontal:int, vertical:int}>|null */
    private ?array $frameComponentSampling = null;

    /** @var array{0:int,1:int}|null */
    private ?array $frameYCbCrSubSampling = null;

    public function __construct(private readonly Stream $stream)
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
     * Returns the precision in bits reported by the primary start of frame segment.
     */
    public function getFrameSamplePrecision(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameBitsPerSample;
    }

    /**
     * Returns the frame height reported by the primary start of frame segment.
     */
    public function getFrameHeight(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameHeight;
    }

    /**
     * Returns the frame width reported by the primary start of frame segment.
     */
    public function getFrameWidth(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameWidth;
    }

    /**
     * Returns the per-component sampling factors captured from the start of frame.
     *
     * @return array<int, array{horizontal:int, vertical:int}>|null
     */
    public function getFrameComponentSamplingFactors(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameComponentSampling;
    }

    /**
     * Returns the derived YCbCr subsampling when the frame describes the components.
     *
     * @return array{0:int,1:int}|null
     */
    public function getFrameYCbCrSubSampling(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameYCbCrSubSampling;
    }

    private function parseIfNeeded(): void
    {
        if ($this->parsed) {
            return;
        }

        $this->parse();
        $this->parsed = true;
    }

    private function parse(): void
    {
        $this->exifBlobs               = [];
        $this->frameBitsPerSample      = null;
        $this->frameHeight             = null;
        $this->frameWidth              = null;
        $this->frameComponentSampling  = null;
        $this->frameYCbCrSubSampling   = null;

        try {
            $this->stream->seek(0);
            $this->assertStartOfImage();

            while (true) {
                $marker = $this->nextMarker();
                if ($marker === null) {
                    break;
                }

                // Start of Scan and End of Image terminate metadata parsing.
                if (($marker === 0xDA) || ($marker === 0xD9)) {
                    break;
                }

                if (!$this->markerHasPayload($marker)) {
                    continue;
                }

                $payload = $this->readSegmentPayload($marker);

                if ($marker === 0xE1) {
                    $this->handleApp1($payload);
                    continue;
                }

                if ($this->isStartOfFrame($marker)) {
                    $this->handleStartOfFrame($marker, $payload);
                }
            }
        } catch (BoundsError $exception) {
            throw new ParseError('Failed to parse JPEG stream', 0, $exception);
        }
    }

    private function assertStartOfImage(): void
    {
        $signature = $this->stream->read(2);
        if ($signature !== "\xFF\xD8") {
            throw new ParseError('Missing JPEG start of image marker.');
        }
    }

    private function nextMarker(): ?int
    {
        while (true) {
            $byte = $this->stream->read(1);
            if ($byte === '') {
                return null;
            }

            if (ord($byte) !== self::MARKER_PREFIX) {
                continue;
            }

            do {
                $code = $this->stream->read(1);
                if ($code === '') {
                    return null;
                }
            } while (ord($code) === self::MARKER_PREFIX);

            return ord($code);
        }
    }

    private function readSegmentPayload(int $marker): string
    {
        $lengthBytes = $this->stream->read(2);
        if (strlen($lengthBytes) !== 2) {
            throw new ParseError(sprintf('JPEG segment 0x%02X truncated length', $marker));
        }

        /** @var array{length:int} $length */
        $length = unpack('nlength', $lengthBytes);
        $payloadSize = $length['length'] - 2;

        if ($payloadSize < 0) {
            throw new ParseError(sprintf('JPEG segment 0x%02X reports negative length', $marker));
        }

        if ($payloadSize > self::MAX_SEGMENT_SIZE) {
            throw new ParseError(sprintf('JPEG segment 0x%02X exceeds safe size limit', $marker));
        }

        $payload = $this->stream->read($payloadSize);
        if (strlen($payload) !== $payloadSize) {
            throw new ParseError(sprintf('JPEG segment 0x%02X truncated payload', $marker));
        }

        return $payload;
    }

    private function handleApp1(string $payload): void
    {
        if (!str_starts_with($payload, self::EXIF_SIGNATURE)) {
            return;
        }

        $tiff = substr($payload, strlen(self::EXIF_SIGNATURE));
        $this->exifBlobs[] = $tiff;
    }

    private function isStartOfFrame(int $marker): bool
    {
        if ($marker < 0xC0 || $marker > 0xCF) {
            return false;
        }

        return !array_key_exists($marker, [
            0xC4 => true, // DHT
            0xC8 => true, // JPG
            0xCC => true, // DAC
        ]);
    }

    private function handleStartOfFrame(int $marker, string $payload): void
    {
        if (strlen($payload) < 6) {
            throw new ParseError(sprintf('SOF marker 0x%02X is too short', $marker));
        }

        $componentCount = ord($payload[5]);
        if ($componentCount === 0) {
            throw new ParseError(sprintf('SOF marker 0x%02X declares zero components', $marker));
        }

        $expectedLength = 6 + ($componentCount * 3);
        if (strlen($payload) < $expectedLength) {
            throw new ParseError(sprintf('SOF marker 0x%02X truncated component table', $marker));
        }

        /** @var array{lines:int,samples:int} $fields */
        $fields = unpack('nlines/nsamples', substr($payload, 1, 4));

        if ($this->frameHeight === null) {
            $this->frameHeight = $fields['lines'];
        }

        if ($this->frameWidth === null) {
            $this->frameWidth = $fields['samples'];
        }

        $components = [];
        $index      = 6;
        for ($i = 0; $i < $componentCount; $i++) {
            $componentId = ord($payload[$index]);
            $sampling    = ord($payload[$index + 1]);
            $horizontal  = $sampling >> 4;
            $vertical    = $sampling & BitMask::LOW_NIBBLE;

            if (($horizontal === 0) || ($vertical === 0)) {
                throw new ParseError(sprintf('SOF marker 0x%02X contains zero sampling factor', $marker));
            }

            $components[$componentId] = [
                'horizontal' => $horizontal,
                'vertical'   => $vertical,
            ];

            $index += 3;
        }

        $this->frameBitsPerSample     = ord($payload[0]);
        $this->frameComponentSampling = $components;
        $this->frameYCbCrSubSampling  = $this->deriveYCbCrSubSampling($components);
    }

    /**
     * Derives YCbCr subsampling factors from component sampling values.
     *
     * @param array<int, array{horizontal:int, vertical:int}> $components
     *
     * @return array{0:int,1:int}|null
     */
    private function deriveYCbCrSubSampling(array $components): ?array
    {
        $luma = $components[1] ?? null;
        if ($luma === null) {
            return null;
        }

        $chromas = [];
        foreach ($components as $id => $component) {
            if ($id !== 1) {
                $chromas[] = $component;
            }
        }

        if ($chromas === []) {
            return null;
        }

        $horizontal = $chromas[0]['horizontal'];
        $vertical   = $chromas[0]['vertical'];

        if ($horizontal === 0 || $vertical === 0) {
            return null;
        }

        $horizontalRatio = intdiv($luma['horizontal'], $horizontal);
        $verticalRatio   = intdiv($luma['vertical'], $vertical);

        return [$horizontalRatio, $verticalRatio];
    }

    private function markerHasPayload(int $marker): bool
    {
        if ($marker === 0x01) {
            return false;
        }

        return ($marker < 0xD0) || ($marker > 0xD7);
    }
}
