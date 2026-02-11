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
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function in_array;
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
 *
 * EXIF 3.0 §4.7.2 documents the APP1 encapsulation for Exif payloads; EXIF 3.0 §4.7.3
 * defines the audio APP2 layout.
 */
final class JpegParser
{
    private const int MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB payload limit

    /**
     * Signatures identifying metadata-bearing APP segments as defined by the
     * Exif JPEG recording rules (EXIF 3.0 §4.7.2).
     */
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    /**
     * Signature identifying MP Index payloads inside APP2 markers (EXIF 3.0 §4.6.4).
     */
    private const string MPF_SIGNATURE = "MPF\0";

    /**
     * Header prefix for Exif audio APP2 payloads (EXIF 3.0 §4.7.3).
     */
    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    private const int AUDIO_HEADER_LENGTH = 24;

    private const int AUDIO_FORMAT_PCM = 0;

    private const int AUDIO_FORMAT_MU_LAW = 1;

    private const int AUDIO_FORMAT_IMA_ADPCM = 2;

    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    private const string FPXR_SIGNATURE = 'FPXR';

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

    /** @var list<JpegAudioStream> */
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
     * @param Stream $stream Stream representing the JPEG binary stream.
     */
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

    /**
     * Returns the parsed MPF document or null when it is unavailable.
     *
     * Triggers lazy parsing via parseIfNeeded() if the stream has not been processed yet.
     *
     * @return MpfDocument|null
     */
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
     * Lazily scans the JPEG structure on first access to collect metadata APP markers.
     *
     * EXIF 3.0 §4.7.1-§4.7.3 require APP1/APP2 metadata segments to reside between the
     * SOI and the first SOS marker while excluding restart and TEM markers from carrying
     * payloads.
     */
    private function parseIfNeeded(): void
    {
        if ($this->parsed) {
            return;
        }

        $this->stream->seek(0);
        if ($this->stream->read(2) !== "\xFF\xD8") {
            throw new ParseError('Not a JPEG (missing SOI marker)', 1263);
        }

        $this->exifBlobs              = [];
        $this->xmpPackets             = [];
        $this->iccSegments            = [];
        $this->iccSequence            = [];
        $this->iccExpectedCount       = null;
        $this->iccProfile             = null;
        $this->flashPixSequences      = [];
        $this->flashPixExpectedCounts = [];
        $this->flashPixStreams        = [];
        $this->mpfSegments            = [];
        $this->mpfFirstOffset         = null;
        $this->mpfDocument            = null;
        $this->audioStreams           = [];
        $this->iptcPayloads           = [];
        $this->xmpPacketHashes        = [];
        $this->frameBitsPerSample     = null;
        $this->frameComponentSampling = null;
        $this->frameYCbCrSubSampling  = null;
        $this->frameLines             = null;
        $this->frameSamplesPerLine    = null;

        while (true) {
            [$marker, $offset] = $this->nextMarkerWithOffset();

            if ($marker === Marker::EOI) {
                break;
            }

            if ($marker === Marker::SOS) {
                break; // EXIF 3.0 §4.7.1 restricts metadata APP markers to precede the first SOS.
            }

            if ($marker === Marker::TEM || ($marker >= Marker::RST_FIRST && $marker <= Marker::RST_LAST)) {
                continue; // EXIF 3.0 §4.7.1 treats restart and TEM markers as non-payload markers.
            }

            $isAppSegment  = $marker >= Marker::APP_FIRST && $marker <= Marker::APP_LAST;
            $segmentLength = $this->readSegmentLength($marker, $offset, $isAppSegment);
            $payloadLength = $segmentLength - 2;
            $payload       = $this->readSegmentPayload($marker, $offset, $payloadLength);

            if ($marker === Marker::APP1) {
                $this->handleApp1($payload);
            } elseif ($marker === Marker::APP2) {
                $this->handleApp2($payload, $offset);
            } elseif ($marker === Marker::APP13) {
                $this->handleApp13($payload);
            } elseif ($marker === Marker::SOF0 || $marker === Marker::SOF2) {
                $this->handleStartOfFrame($marker, $payload, $offset);
            }
        }

        if (
            $this->iccExpectedCount > 0
            && count($this->iccSequence) === $this->iccExpectedCount
        ) {
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
                if ($expectedCount === 0) {
                    continue;
                }

                if (count($fragments) !== $expectedCount) {
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
                    1264,
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
            $byte = $this->stream->read(1);
            while ($byte !== "\xFF") {
                $byte = $this->stream->read(1);
            }

            $markerOffset = $this->stream->tell() - 1;

            do {
                $code = $this->stream->read(1);
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
        $length = $this->stream->readU16BE();

        if ($length < 2) {
            throw new ParseError(
                sprintf('Segment length %d for marker 0x%02X at offset %d is invalid', $length, $marker, $offset),
                1265,
            );
        }

        if ($enforceMax) {
            $payloadLength = $length - 2;
            if ($payloadLength > self::MAX_APP_SEGMENT_SIZE) {
                // EXIF 3.0 §4.5.2 keeps APP1/APP2 payloads within the JPEG
                // 64 KiB segment budget; this wider ceiling rejects obviously pathological blobs
                // before the TIFF parser is invoked.
                throw new ParseError(
                    sprintf(
                        'APP segment 0x%02X at offset %d exceeds maximum payload of %d bytes',
                        $marker,
                        $offset,
                        self::MAX_APP_SEGMENT_SIZE,
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
     *
     * @return string
     */
    private function readSegmentPayload(int $marker, int $offset, int $length): string
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

    /**
     * Processes APP1 payloads for Exif and XMP signatures.
     *
     * EXIF 3.0 §4.7.2 mandates that Exif data inside APP1 begins with "Exif\0\0"
     * followed by the TIFF header defined in §4.5.
     *
     * @param string $payload Raw APP1 payload including leading signature.
     */
    private function handleApp1(string $payload): void
    {
        if (str_starts_with($payload, self::EXIF_SIGNATURE)) {
            // EXIF 3.0 §4.7.2 requires APP1 Exif data to start with "Exif\0\0"
            // followed by a valid TIFF header (byte order + magic number).
            $tiffData = substr($payload, strlen(self::EXIF_SIGNATURE));
            $this->validateApp1TiffHeader($tiffData);
            $this->exifBlobs[] = $tiffData;
        } elseif (str_starts_with($payload, self::XMP_SIGNATURE)) {
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
        } elseif (str_starts_with($payload, self::MPF_SIGNATURE)) {
            $this->handleMpfSegment($payload, $offset);
        } elseif (str_starts_with($payload, self::FPXR_SIGNATURE)) {
            $this->handleFlashPixSegment($payload, $offset);
        } elseif (str_starts_with($payload, self::AUDIO_SIGNATURE)) {
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
            throw new ParseError(sprintf('ICC segment at offset %d is too short', $offset), 1268);
        }

        $sequenceNumber = ord($payload[$signatureLength]);
        $sequenceCount  = ord($payload[$signatureLength + 1]);
        $iccData        = substr($payload, $signatureLength + 2);

        $this->iccSegments[] = $payload;

        // GH-896: sequence count must not be zero
        if ($sequenceCount === 0) {
            throw new ParseError(
                sprintf('ICC segment at offset %d has zero sequence count', $offset),
                1301,
            );
        }

        // GH-896: sequence number must be in range 1..sequenceCount
        if ($sequenceNumber === 0 || $sequenceNumber > $sequenceCount) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has out-of-range sequence number %d (expected 1..%d)',
                    $offset,
                    $sequenceNumber,
                    $sequenceCount,
                ),
                1302,
            );
        }

        // GH-896: all chunks must agree on the total count
        if ($this->iccExpectedCount === null) {
            $this->iccExpectedCount = $sequenceCount;
        } elseif ($this->iccExpectedCount !== $sequenceCount) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has inconsistent sequence count (%d vs %d)',
                    $offset,
                    $sequenceCount,
                    $this->iccExpectedCount,
                ),
                1303,
            );
        }

        // GH-852: reject duplicate sequence numbers
        if (array_key_exists($sequenceNumber, $this->iccSequence)) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has duplicate sequence number %d',
                    $offset,
                    $sequenceNumber,
                ),
                1304,
            );
        }

        $this->iccSequence[$sequenceNumber] = $iccData;
    }

    /**
     * Processes Exif audio APP2 segments and validates their headers.
     *
     * EXIF 3.0 §4.7.3 defines the APP2 audio stream format, including the
     * four-byte sample rate and two-byte version fields honoured here.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleAudioSegment(string $payload, int $offset): void
    {
        $length = strlen($payload);
        if ($length < self::AUDIO_HEADER_LENGTH) {
            throw new ParseError(sprintf('Audio segment at offset %d is too short', $offset), 1269);
        }

        $signatureLength = strlen(self::AUDIO_SIGNATURE);
        $major           = ord($payload[$signatureLength]);
        $minor           = ord($payload[$signatureLength + 1]);
        $format          = ord($payload[$signatureLength + 2]);
        $channels        = ord($payload[$signatureLength + 3]);

        $sampleRateData   = substr($payload, $signatureLength + 4, 4);
        $sampleRateUnpack = @unpack('Nrate', $sampleRateData);

        if (($sampleRateUnpack === false) || !isset($sampleRateUnpack['rate'])) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid sample rate field', $offset), 1270);
        }

        /** @var array{rate:int} $sampleRateUnpack */
        $sampleRate = $sampleRateUnpack['rate'];
        $bitDepth   = ord($payload[$signatureLength + 8]);

        $sampleCountData   = substr($payload, $signatureLength + 9, 4);
        $sampleCountUnpack = @unpack('Ncount', $sampleCountData);

        if (($sampleCountUnpack === false) || !isset($sampleCountUnpack['count'])) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid sample count field', $offset), 1271);
        }

        /** @var array{count:int} $sampleCountUnpack */
        $sampleCount = $sampleCountUnpack['count'];
        $data        = substr($payload, self::AUDIO_HEADER_LENGTH);

        if ($channels === 0 || $channels > 2) {
            throw new ParseError(sprintf('Audio segment at offset %d has unsupported channel count %d', $offset, $channels), 1272);
        }

        $allowedSampleRates = [8_000, 11_025, 22_050, 44_100];
        if (!in_array($sampleRate, $allowedSampleRates, true)) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported sample rate %d', $offset, $sampleRate), 1273);
        }

        if ($format === self::AUDIO_FORMAT_MU_LAW && $sampleRate !== 8_000) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported μ-law sample rate %d', $offset, $sampleRate), 1274);
        }

        $formatName = match ($format) {
            self::AUDIO_FORMAT_PCM       => 'PCM',
            self::AUDIO_FORMAT_MU_LAW    => 'MU_LAW_PCM',
            self::AUDIO_FORMAT_IMA_ADPCM => 'IMA_ADPCM',
            default                      => null,
        };

        if ($formatName === null) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unknown format %d', $offset, $format), 1275);
        }

        if ($format === self::AUDIO_FORMAT_PCM && !in_array($bitDepth, [8, 16], true)) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid PCM bit depth %d', $offset, $bitDepth), 1276);
        }

        if ($format === self::AUDIO_FORMAT_MU_LAW && $bitDepth !== 8) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid μ-law bit depth %d', $offset, $bitDepth), 1277);
        }

        if ($format === self::AUDIO_FORMAT_IMA_ADPCM && $bitDepth !== 4) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid IMA-ADPCM bit depth %d', $offset, $bitDepth), 1278);
        }

        if ($sampleCount > 0 && $format !== self::AUDIO_FORMAT_IMA_ADPCM) {
            $bytesPerSample = (int) (($bitDepth / 8) * $channels);
            if ($bytesPerSample > 0) {
                $expectedLength = $sampleCount * $bytesPerSample;
                if ($expectedLength !== strlen($data)) {
                    throw new ParseError(sprintf('Audio segment at offset %d has inconsistent data length', $offset), 1279);
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
     *
     * EXIF 3.0 §4.6.4 specifies that Multi-Picture Format data resides in APP2 markers.
     */
    private function handleMpfSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::MPF_SIGNATURE);
        if (strlen($payload) <= $signatureLength) {
            throw new ParseError(sprintf('MPF segment at offset %d is too short', $offset), 1280);
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
            throw new ParseError(sprintf('FlashPix segment at offset %d is too short', $offset), 1281);
        }

        $header   = substr($payload, $signatureLength, 4);
        $unpacked = @unpack('nstream/Csequence/Ccount', $header);

        if (($unpacked === false) || !isset($unpacked['stream'], $unpacked['sequence'], $unpacked['count'])) {
            throw new ParseError(sprintf('Unable to parse FlashPix segment header at offset %d', $offset), 1282);
        }

        /** @var array{stream:int, sequence:int, count:int} $unpacked */
        $streamId       = $unpacked['stream'];
        $sequenceNumber = $unpacked['sequence'];
        $sequenceCount  = $unpacked['count'];
        $data           = substr($payload, $signatureLength + 4);

        $shouldStoreStream = true;

        if ($sequenceNumber === 0 || $sequenceCount === 0 || $sequenceNumber > $sequenceCount) {
            $this->flashPixExpectedCounts[$streamId] = 0;
            unset($this->flashPixSequences[$streamId]);
            $shouldStoreStream = false;
        } else {
            if (!array_key_exists($streamId, $this->flashPixExpectedCounts)) {
                $this->flashPixExpectedCounts[$streamId] = $sequenceCount;
            } elseif ($this->flashPixExpectedCounts[$streamId] !== $sequenceCount) {
                $this->flashPixExpectedCounts[$streamId] = 0;
                unset($this->flashPixSequences[$streamId]);
                $shouldStoreStream = false;
            }

            if ($shouldStoreStream && !array_key_exists($streamId, $this->flashPixSequences)) {
                $this->flashPixSequences[$streamId] = [];
            }

            if ($shouldStoreStream && !array_key_exists($sequenceNumber, $this->flashPixSequences[$streamId])) {
                $this->flashPixSequences[$streamId][$sequenceNumber] = $data;
            }
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
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d is too short', $marker, $offset), 1283);
        }

        $componentCount = ord($payload[5]);
        if ($componentCount === 0) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d reports zero components', $marker, $offset), 1284);
        }

        $expectedLength = 6 + ($componentCount * 3);
        if ($length < $expectedLength) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d is truncated', $marker, $offset), 1285);
        }

        $components = [];
        $index      = 6;

        for ($i = 0; $i < $componentCount; ++$i) {
            $componentId     = ord($payload[$index]);
            $samplingFactors = ord($payload[$index + 1]);
            $horizontal      = $samplingFactors >> 4;
            $vertical        = $samplingFactors & BitMask::LOW_NIBBLE;

            if ($horizontal === 0 || $vertical === 0) {
                throw new ParseError(
                    sprintf('SOF marker 0x%02X at offset %d contains zero sampling factor', $marker, $offset),
                    1286,
                );
            }

            $components[$componentId] = [
                'horizontal' => $horizontal,
                'vertical'   => $vertical,
            ];

            $index += 3;
        }

        $fields = @unpack('nlines/nsamples', substr($payload, 1, 4));

        if (($fields === false) || !isset($fields['lines'], $fields['samples'])) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d has invalid dimensions', $marker, $offset), 1287);
        }

        /** @var array{lines:int,samples:int} $fields */
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
     * EXIF 3.0 §4.6.5.1.12 (YCbCrSubSampling) defines only [2,1] (YCbCr4:2:2) and
     * [2,2] (YCbCr4:2:0) as legal values. Derived values outside this set are rejected.
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

        $count = count($chromas);
        for ($i = 1; $i < $count; ++$i) {
            $component  = $chromas[$i];
            $horizontal = min($horizontal, $component['horizontal']);
            $vertical   = min($vertical, $component['vertical']);
        }

        if ((($luma['horizontal'] % $horizontal) !== 0) || (($luma['vertical'] % $vertical) !== 0)) {
            return null;
        }

        $derivedH = (int) ($luma['horizontal'] / $horizontal);
        $derivedV = (int) ($luma['vertical'] / $vertical);

        // EXIF 3.0 §4.6.5.1.12: legal values are [2,1] (YCbCr4:2:2) and [2,2] (YCbCr4:2:0)
        $legalValues = [
            [2, 1],
            [2, 2],
        ];

        $result = array_any(
            $legalValues,
            fn ($legal): bool => $derivedH === $legal[0] && $derivedV === $legal[1]
        );

        if ($result) {
            return [
                $derivedH,
                $derivedV,
            ];
        }

        return null;
    }

    /**
     * Validates the TIFF header region after the Exif signature.
     *
     * EXIF 3.0 §4.7.2 requires APP1 Exif payload to carry a structurally valid TIFF header:
     * byte-order marker (II or MM) followed by TIFF magic (0x002A) or BigTIFF magic (0x002B).
     *
     * @param string $tiffData Raw bytes after the "Exif\0\0" signature.
     */
    private function validateApp1TiffHeader(string $tiffData): void
    {
        // Minimum TIFF header is 4 bytes: 2 byte-order + 2 magic
        if (strlen($tiffData) < 4) {
            throw new ParseError('APP1 Exif payload too short for TIFF header', 1400);
        }

        $byteOrder = substr($tiffData, 0, 2);
        if ($byteOrder !== 'II' && $byteOrder !== 'MM') {
            throw new ParseError('APP1 Exif TIFF header has invalid byte order', 1401);
        }

        $format   = $byteOrder === 'II' ? 'v' : 'n';
        $unpacked = @unpack($format, substr($tiffData, 2, 2));

        if (($unpacked === false) || !isset($unpacked[1])) {
            throw new ParseError('APP1 Exif TIFF header has invalid magic number', 1402);
        }

        /** @var array{1:int} $unpacked */
        if ($unpacked[1] !== 0x002A && $unpacked[1] !== 0x002B) {
            throw new ParseError('APP1 Exif TIFF header has invalid magic number', 1402);
        }
    }
}
