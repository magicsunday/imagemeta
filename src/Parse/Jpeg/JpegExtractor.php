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
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function ksort;
use function min;
use function ord;
use function range;
use function sha1;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

/**
 * Parses JPEG streams to extract metadata-bearing APP segments.
 */
final class JpegExtractor
{
    private const int MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB payload limit

    /**
     * APP marker codes carrying metadata of interest.
     *
     * APP1  (0xE1) exposes EXIF and XMP metadata, APP2 (0xE2) carries ICC
     * profile fragments, and APP13 (0xED) contains IPTC/Photoshop resources.
     */
    private const int MARKER_APP1 = 0xE1;

    private const int MARKER_APP2 = 0xE2;

    private const int MARKER_APP13 = 0xED;

    private const int MARKER_SOF0 = 0xC0;

    private const int MARKER_SOF2 = 0xC2;

    /**
     * Signatures identifying metadata-bearing APP segments.
     */
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    private const string MPF_SIGNATURE = "MPF\0";

    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    private const int AUDIO_HEADER_LENGTH = 24;

    private const int AUDIO_FORMAT_PCM = 0;

    private const int AUDIO_FORMAT_MU_LAW = 1;

    private const int AUDIO_FORMAT_IMA_ADPCM = 2;

    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const string FPXR_SIGNATURE = 'FPXR';

    /**
     * Scan and termination markers that end metadata scanning.
     */
    private const int MARKER_SOS = 0xDA;

    private const int MARKER_EOI = 0xD9;

    private bool $parsed = false;

    /** @var list<string> */
    private array $exifBlobs = [];

    /** @var list<string> */
    private array $xmpPackets = [];

    /** @var array<string, bool> */
    private array $xmpPacketHashes = [];

    /** @var list<string> */
    private array $iccSegments = [];

    /** @var array<int, string> */
    private array $iccSequence = [];

    private ?int $iccExpectedCount = null;

    private ?string $iccProfile = null;

    /** @var array<int, array<int, string>> */
    private array $flashPixSequences = [];

    /** @var array<int, int> */
    private array $flashPixExpectedCounts = [];

    /** @var array<int, string> */
    private array $flashPixStreams = [];

    /** @var list<string> */
    private array $mpfSegments = [];

    private ?int $mpfFirstOffset = null;

    private ?MpfDocument $mpfDocument = null;

    /** @var list<\MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream> */
    private array $audioStreams = [];

    /** @var list<string> */
    private array $iptcPayloads = [];

    private ?int $frameBitsPerSample = null;

    private ?int $frameLines = null;

    private ?int $frameSamplesPerLine = null;

    /** @var array<int, array{horizontal: int, vertical: int}>|null */
    private ?array $frameComponentSampling = null;

    /** @var array{0:int,1:int}|null */
    private ?array $frameYCbCrSubSampling = null;

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
     * Returns concatenated FlashPix extension streams keyed by their stream identifier.
     *
     * @return array<int, string>
     */
    public function getFlashPixStreams(): array
    {
        $this->parseIfNeeded();

        return $this->flashPixStreams;
    }

    /**
     * Returns EXIF audio streams discovered in APP2 markers.
     *
     * @return list<JpegAudioStream>
     */
    public function getAudioStreams(): array
    {
        $this->parseIfNeeded();

        return $this->audioStreams;
    }

    public function getMpfDocument(): ?MpfDocument
    {
        $this->parseIfNeeded();

        return $this->mpfDocument;
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

        return $this->frameLines;
    }

    /**
     * Returns the frame width reported by the primary start of frame segment.
     */
    public function getFrameWidth(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameSamplesPerLine;
    }

    /**
     * Returns the horizontal and vertical sampling factors for components identified in the SOF.
     *
     * @return array<int, array{horizontal:int, vertical:int}>|null
     */
    public function getFrameComponentSamplingFactors(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameComponentSampling;
    }

    /**
     * Returns the derived YCbCr subsampling factors inferred from the SOF component sampling.
     *
     * @return array{0:int,1:int}|null
     */
    public function getFrameYCbCrSubSampling(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameYCbCrSubSampling;
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

        $this->exifBlobs              = [];
        $this->xmpPackets             = [];
        $this->iccSegments            = [];
        $this->iccSequence            = [];
        $this->iccExpectedCount       = null;
        $this->iccProfile             = null;
        $this->flashPixSequences       = [];
        $this->flashPixExpectedCounts  = [];
        $this->flashPixStreams         = [];
        $this->mpfSegments             = [];
        $this->mpfFirstOffset          = null;
        $this->mpfDocument             = null;
        $this->audioStreams            = [];
        $this->iptcPayloads            = [];
        $this->xmpPacketHashes         = [];
        $this->frameBitsPerSample      = null;
        $this->frameComponentSampling  = null;
        $this->frameYCbCrSubSampling   = null;
        $this->frameLines              = null;
        $this->frameSamplesPerLine     = null;

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

            if ($marker === self::MARKER_APP1) {
                $this->handleApp1($payload);
            } elseif ($marker === self::MARKER_APP2) {
                $this->handleApp2($payload, $offset);
            } elseif ($marker === self::MARKER_APP13) {
                $this->handleApp13($payload);
            } elseif ($marker === self::MARKER_SOF0 || $marker === self::MARKER_SOF2) {
                $this->handleStartOfFrame($marker, $payload, $offset);
            }
        }

        if ($this->iccExpectedCount !== null && $this->iccExpectedCount > 0 && count($this->iccSequence) === $this->iccExpectedCount) {
            $expectedSequence = range(1, $this->iccExpectedCount);
            $presentSequence  = array_keys($this->iccSequence);
            sort($presentSequence);
            if ($presentSequence === $expectedSequence) {
                ksort($this->iccSequence);
                $this->iccProfile = implode('', $this->iccSequence);
            }
        }

        if ($this->flashPixSequences !== []) {
            foreach ($this->flashPixSequences as $streamId => $fragments) {
                $expectedCount = $this->flashPixExpectedCounts[$streamId] ?? 0;
                if ($expectedCount === 0 || count($fragments) !== $expectedCount) {
                    continue;
                }

                $sequenceNumbers = array_keys($fragments);
                sort($sequenceNumbers);

                if ($sequenceNumbers !== range(1, $expectedCount)) {
                    continue;
                }

                ksort($fragments);
                $this->flashPixStreams[$streamId] = implode('', $fragments);
            }

            if ($this->flashPixStreams !== []) {
                ksort($this->flashPixStreams);
            }
        }

        if ($this->mpfSegments !== []) {
            $payload = implode('', $this->mpfSegments);

            try {
                $this->mpfDocument = (new MpfParser())->parse($payload);
            } catch (ParseError $exception) {
                $offset = $this->mpfFirstOffset ?? 0;

                throw new ParseError(
                    sprintf('Invalid MPF payload at offset %d', $offset),
                    0,
                    $exception,
                );
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
            $packet = substr($payload, strlen(self::XMP_SIGNATURE));
            $hash   = sha1($packet);

            if (!array_key_exists($hash, $this->xmpPacketHashes)) {
                $this->xmpPacketHashes[$hash] = true;
                $this->xmpPackets[]           = $packet;
            }
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
        if (str_starts_with($payload, self::ICC_SIGNATURE)) {
            $this->handleIccSegment($payload, $offset);

            return;
        }

        if (str_starts_with($payload, self::MPF_SIGNATURE)) {
            $this->handleMpfSegment($payload, $offset);

            return;
        }

        if (str_starts_with($payload, self::FPXR_SIGNATURE)) {
            $this->handleFlashPixSegment($payload, $offset);

            return;
        }

        if (str_starts_with($payload, self::AUDIO_SIGNATURE)) {
            $this->handleAudioSegment($payload, $offset);
        }
    }

    /**
     * Processes ICC profile segments contained within APP2 markers.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleIccSegment(string $payload, int $offset): void
    {
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
     * Processes EXIF audio APP2 segments and validates their headers.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleAudioSegment(string $payload, int $offset): void
    {
        $length = strlen($payload);
        if ($length < self::AUDIO_HEADER_LENGTH) {
            throw new ParseError(sprintf('Audio segment at offset %d is too short', $offset));
        }

        $signatureLength = strlen(self::AUDIO_SIGNATURE);
        $major           = ord($payload[$signatureLength]);
        $minor           = ord($payload[$signatureLength + 1]);
        $format          = ord($payload[$signatureLength + 2]);
        $channels        = ord($payload[$signatureLength + 3]);

        $sampleRateData = substr($payload, $signatureLength + 4, 4);
        $sampleRateUnpack = unpack('Nrate', $sampleRateData);
        if ($sampleRateUnpack === false) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid sample rate field', $offset));
        }

        $sampleRate = (int) $sampleRateUnpack['rate'];
        $bitDepth   = ord($payload[$signatureLength + 8]);

        $sampleCountData = substr($payload, $signatureLength + 9, 4);
        $sampleCountUnpack = unpack('Ncount', $sampleCountData);
        if ($sampleCountUnpack === false) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid sample count field', $offset));
        }

        $sampleCount = (int) $sampleCountUnpack['count'];
        $data        = substr($payload, self::AUDIO_HEADER_LENGTH);

        if ($channels === 0 || $channels > 2) {
            throw new ParseError(sprintf('Audio segment at offset %d has unsupported channel count %d', $offset, $channels));
        }

        $allowedSampleRates = [8_000, 11_025, 22_050, 44_100];
        if (!in_array($sampleRate, $allowedSampleRates, true)) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported sample rate %d', $offset, $sampleRate));
        }

        if ($format === self::AUDIO_FORMAT_MU_LAW && $sampleRate !== 8_000) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported μ-law sample rate %d', $offset, $sampleRate));
        }

        $formatName = match ($format) {
            self::AUDIO_FORMAT_PCM       => 'PCM',
            self::AUDIO_FORMAT_MU_LAW    => 'MU_LAW_PCM',
            self::AUDIO_FORMAT_IMA_ADPCM => 'IMA_ADPCM',
            default => null,
        };

        if ($formatName === null) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unknown format %d', $offset, $format));
        }

        if ($format === self::AUDIO_FORMAT_PCM && !in_array($bitDepth, [8, 16], true)) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid PCM bit depth %d', $offset, $bitDepth));
        }

        if ($format === self::AUDIO_FORMAT_MU_LAW && $bitDepth !== 8) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid μ-law bit depth %d', $offset, $bitDepth));
        }

        if ($format === self::AUDIO_FORMAT_IMA_ADPCM && $bitDepth !== 4) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid IMA-ADPCM bit depth %d', $offset, $bitDepth));
        }

        if ($sampleCount > 0 && $format !== self::AUDIO_FORMAT_IMA_ADPCM) {
            $bytesPerSample = (int) (($bitDepth / 8) * $channels);
            if ($bytesPerSample > 0) {
                $expectedLength = (int) ($sampleCount * $bytesPerSample);
                if ($expectedLength !== strlen($data)) {
                    throw new ParseError(sprintf('Audio segment at offset %d has inconsistent data length', $offset));
                }
            }
        }

        $version = sprintf('%d.%02d', $major, $minor);

        $this->audioStreams[] = new JpegAudioStream(
            $formatName,
            $channels,
            $sampleRate,
            $bitDepth,
            $data,
            $version,
        );
    }

    /**
     * Collects MPF APP2 segments to be parsed after the marker scan completes.
     */
    private function handleMpfSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::MPF_SIGNATURE);
        if (strlen($payload) <= $signatureLength) {
            throw new ParseError(sprintf('MPF segment at offset %d is too short', $offset));
        }

        if ($this->mpfSegments === []) {
            $this->mpfFirstOffset = $offset;
        }

        $this->mpfSegments[] = substr($payload, $signatureLength);
    }

    /**
     * Processes FlashPix extension segments contained within APP2 markers.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleFlashPixSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::FPXR_SIGNATURE);
        if (strlen($payload) < $signatureLength + 4) {
            throw new ParseError(sprintf('FlashPix segment at offset %d is too short', $offset));
        }

        $header = substr($payload, $signatureLength, 4);
        $unpacked = unpack('nstream/Csequence/Ccount', $header);
        if ($unpacked === false) {
            throw new ParseError(sprintf('Unable to parse FlashPix segment header at offset %d', $offset));
        }

        $streamId = (int) $unpacked['stream'];
        $sequenceNumber = (int) $unpacked['sequence'];
        $sequenceCount = (int) $unpacked['count'];
        $data = substr($payload, $signatureLength + 4);

        if ($sequenceNumber === 0 || $sequenceCount === 0 || $sequenceNumber > $sequenceCount) {
            $this->flashPixExpectedCounts[$streamId] = 0;
            unset($this->flashPixSequences[$streamId]);

            return;
        }

        if (!array_key_exists($streamId, $this->flashPixExpectedCounts)) {
            $this->flashPixExpectedCounts[$streamId] = $sequenceCount;
        } elseif ($this->flashPixExpectedCounts[$streamId] !== $sequenceCount) {
            $this->flashPixExpectedCounts[$streamId] = 0;
            unset($this->flashPixSequences[$streamId]);

            return;
        }

        if (!array_key_exists($streamId, $this->flashPixSequences)) {
            $this->flashPixSequences[$streamId] = [];
        }

        if (!array_key_exists($sequenceNumber, $this->flashPixSequences[$streamId])) {
            $this->flashPixSequences[$streamId][$sequenceNumber] = $data;
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

    /**
     * Parses baseline or progressive start of frame markers to obtain sampling information.
     *
     * @param int    $marker  Marker code (SOF0 or SOF2).
     * @param string $payload Raw SOF payload excluding the marker and length field.
     * @param int    $offset  Offset where the SOF marker begins.
     */
    private function handleStartOfFrame(int $marker, string $payload, int $offset): void
    {
        if ($this->frameBitsPerSample !== null) {
            return;
        }

        $length = strlen($payload);
        if ($length < 6) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d is too short', $marker, $offset));
        }

        $componentCount = ord($payload[5]);
        if ($componentCount === 0) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d reports zero components', $marker, $offset));
        }

        $expectedLength = 6 + ($componentCount * 3);
        if ($length < $expectedLength) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d is truncated', $marker, $offset));
        }

        $components = [];
        $index      = 6;

        for ($i = 0; $i < $componentCount; ++$i) {
            $componentId     = ord($payload[$index]);
            $samplingFactors = ord($payload[$index + 1]);
            $horizontal      = $samplingFactors >> 4;
            $vertical        = $samplingFactors & 0x0F;

            if ($horizontal === 0 || $vertical === 0) {
                throw new ParseError(
                    sprintf('SOF marker 0x%02X at offset %d contains zero sampling factor', $marker, $offset)
                );
            }

            $components[$componentId] = [
                'horizontal' => $horizontal,
                'vertical'   => $vertical,
            ];

            $index += 3;
        }

        /** @var array{lines:int,samples:int} $fields */
        $fields = unpack('nlines/nsamples', substr($payload, 1, 4));

        if ($this->frameLines === null) {
            $this->frameLines = $fields['lines'];
        }

        if ($this->frameSamplesPerLine === null) {
            $this->frameSamplesPerLine = $fields['samples'];
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
            if ($id === 1) {
                continue;
            }

            $chromas[] = $component;
        }

        if ($chromas === []) {
            return null;
        }

        $horizontal = $chromas[0]['horizontal'];
        $vertical   = $chromas[0]['vertical'];

        if ($horizontal === 0 || $vertical === 0) {
            return null;
        }

        $count = count($chromas);
        for ($i = 1; $i < $count; ++$i) {
            $component  = $chromas[$i];
            $horizontal = min($horizontal, $component['horizontal']);
            $vertical   = min($vertical, $component['vertical']);
        }

        if ($luma['horizontal'] % $horizontal !== 0 || $luma['vertical'] % $vertical !== 0) {
            return null;
        }

        return [
            (int) ($luma['horizontal'] / $horizontal),
            (int) ($luma['vertical'] / $vertical),
        ];
    }
}
