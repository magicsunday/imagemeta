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
use function iconv;
use function implode;
use function in_array;
use function ksort;
use function min;
use function ord;
use function range;
use function sha1;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function unpack;
use function usort;

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

    private const int FLASHPIX_STORAGE_ENTITY_SIZE = 0xFFFFFFFF;

    private const int FLASHPIX_MAX_CONTENT_ENTRIES = 1024;

    private const int FLASHPIX_MAX_STREAM_SIZE = 16_777_216; // 16 MiB per stream

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

    /** @var array<int, array{size:int, defaultByte:int, isStorage:bool}> */
    private array $flashPixContents = [];

    /** @var array<int, list<array{offset:int, data:string}>> */
    private array $flashPixChunks = [];

    /** @var array<int, list<array{start:int, end:int}>> */
    private array $flashPixRanges = [];

    private bool $flashPixContentsSeen = false;

    private ?int $flashPixLastStreamIndex = null;

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
     * Returns concatenated FlashPix extension streams keyed by contents-list index.
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

        $this->exifBlobs               = [];
        $this->xmpPackets              = [];
        $this->iccSegments             = [];
        $this->iccSequence             = [];
        $this->iccExpectedCount        = null;
        $this->iccProfile              = null;
        $this->flashPixContents        = [];
        $this->flashPixChunks          = [];
        $this->flashPixRanges          = [];
        $this->flashPixContentsSeen    = false;
        $this->flashPixLastStreamIndex = null;
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

        $seenExifApp1                = false;
        $markersBeforeExifApp1       = 0;
        $firstMarkerBeforeExifOffset = null;
        $firstApp2BeforeExifOffset   = null;
        $seenApp1OrApp2              = false;
        $seenApp11                   = false;
        $firstStructuralMarker       = null;
        $firstStructuralMarkerOffset = null;

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

            if (!$seenExifApp1) {
                $isExifApp1 = ($marker === Marker::APP1) && str_starts_with($payload, self::EXIF_SIGNATURE);

                if ($isExifApp1) {
                    if ($firstApp2BeforeExifOffset !== null) {
                        throw new ParseError(
                            sprintf(
                                'EXIF APP2 marker at offset %d appears before APP1 Exif marker',
                                $firstApp2BeforeExifOffset,
                            ),
                            1326,
                        );
                    }

                    if (($markersBeforeExifApp1 > 0) && ($firstMarkerBeforeExifOffset !== null)) {
                        throw new ParseError(
                            sprintf(
                                'APP1 Exif marker at offset %d must be the first metadata marker after SOI (first marker seen at offset %d)',
                                $offset,
                                $firstMarkerBeforeExifOffset,
                            ),
                            1327,
                        );
                    }

                    $seenExifApp1 = true;
                } else {
                    ++$markersBeforeExifApp1;

                    if ($firstMarkerBeforeExifOffset === null) {
                        $firstMarkerBeforeExifOffset = $offset;
                    }

                    if (
                        ($firstApp2BeforeExifOffset === null)
                        && ($marker === Marker::APP2)
                        && $this->isExifApp2ExtensionPayload($payload)
                    ) {
                        $firstApp2BeforeExifOffset = $offset;
                    }
                }
            }

            if ($seenApp11 && ($marker === Marker::APP1 || $marker === Marker::APP2)) {
                throw new ParseError(
                    sprintf(
                        'APP1/APP2 marker at offset %d appears after APP11 marker',
                        $offset,
                    ),
                    1330,
                );
            }

            if ($marker === Marker::APP11) {
                if (!$seenApp1OrApp2) {
                    throw new ParseError(
                        sprintf(
                            'APP11 marker at offset %d appears before APP1/APP2 metadata region',
                            $offset,
                        ),
                        1328,
                    );
                }

                if (($firstStructuralMarker !== null) && ($firstStructuralMarkerOffset !== null)) {
                    throw new ParseError(
                        sprintf(
                            'APP11 marker at offset %d appears after structural marker 0x%02X at offset %d',
                            $offset,
                            $firstStructuralMarker,
                            $firstStructuralMarkerOffset,
                        ),
                        1329,
                    );
                }

                $seenApp11 = true;
            }

            if ($marker === Marker::APP1 || $marker === Marker::APP2) {
                $seenApp1OrApp2 = true;
            }

            if (($firstStructuralMarkerOffset === null) && $this->isStructuralMarkerBeforeScan($marker)) {
                $firstStructuralMarker       = $marker;
                $firstStructuralMarkerOffset = $offset;
            }

            if ($marker === Marker::APP1) {
                $this->handleApp1($payload);
            } elseif ($marker === Marker::APP2) {
                $this->handleApp2($payload, $offset);
            } elseif ($marker === Marker::APP11) {
                $this->handleApp11($payload, $offset);
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

        $this->finaliseFlashPixStreams();

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
     * Determines whether an APP2 payload contains EXIF-defined extension data.
     *
     * EXIF 3.0 §4.7.3 applies ordering constraints to FlashPix, MPF, and EXIF audio APP2 segments.
     *
     * @param string $payload Raw APP2 payload.
     *
     * @return bool
     */
    private function isExifApp2ExtensionPayload(string $payload): bool
    {
        return str_starts_with($payload, self::FPXR_SIGNATURE)
            || str_starts_with($payload, self::MPF_SIGNATURE)
            || str_starts_with($payload, self::AUDIO_SIGNATURE);
    }

    /**
     * Determines whether the marker begins structural image coding segments before scan data.
     *
     * EXIF 3.0 §4.7.5.2 requires APP11 to be located before DQT/DHT/DRI/SOF marker segments.
     *
     * @param int $marker Marker code.
     *
     * @return bool True when the marker is a structural pre-scan marker.
     */
    private function isStructuralMarkerBeforeScan(int $marker): bool
    {
        return in_array($marker, [Marker::DQT, Marker::DHT, Marker::DRI], true)
            || $this->isStartOfFrameMarker($marker);
    }

    /**
     * Determines whether the marker is one of the JPEG Start Of Frame marker codes.
     *
     * @param int $marker Marker code.
     *
     * @return bool True when the marker is a SOF marker.
     */
    private function isStartOfFrameMarker(int $marker): bool
    {
        return in_array(
            $marker,
            [
                Marker::SOF0,
                Marker::SOF1,
                Marker::SOF2,
                Marker::SOF3,
                Marker::SOF5,
                Marker::SOF6,
                Marker::SOF7,
                Marker::SOF9,
                Marker::SOF10,
                Marker::SOF11,
                Marker::SOF13,
                Marker::SOF14,
                Marker::SOF15,
            ],
            true,
        );
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
            $this->appendXmpPacket($packet);
        }
    }

    /**
     * Stores an XMP packet while preserving first-seen order and deduplicating by hash.
     *
     * @param string $packet Raw XMP packet body.
     */
    private function appendXmpPacket(string $packet): void
    {
        $hash = sha1($packet);

        if (!array_key_exists($hash, $this->xmpPacketHashes)) {
            $this->xmpPacketHashes[$hash] = true;
            $this->xmpPackets[]           = $packet;
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
     * Processes APP11 payloads carrying JUMBF box-structured metadata.
     *
     * EXIF 3.0 §4.7.5.3 defines the APP11 transport wrapper and stores JUMBF
     * superboxes for annotation metadata. Supported XML/XMP payloads are
     * surfaced through the existing XMP packet collection.
     *
     * @param string $payload Raw APP11 payload.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleApp11(string $payload, int $offset): void
    {
        if (!str_starts_with($payload, 'JP')) {
            return;
        }

        $jumbfSuperbox = $this->extractApp11JumbfSuperbox($payload, $offset);
        $this->collectApp11XmlPacketsFromBoxes($jumbfSuperbox, $offset);
    }

    /**
     * Extracts the first valid JUMBF superbox from an APP11 payload.
     *
     * @param string $payload APP11 payload bytes.
     * @param int    $offset  APP11 marker offset for diagnostics.
     *
     * @return string Raw bytes of the JUMBF superbox.
     */
    private function extractApp11JumbfSuperbox(string $payload, int $offset): string
    {
        $length = strlen($payload);
        if ($length < 12) {
            throw new ParseError(sprintf('APP11 segment at offset %d is too short', $offset), 1331);
        }

        for ($position = 0; $position + 8 <= $length; ++$position) {
            if (substr($payload, $position + 4, 4) !== 'jumb') {
                continue;
            }

            $sizeUnpack = @unpack('Nsize', substr($payload, $position, 4));
            if (($sizeUnpack === false) || !isset($sizeUnpack['size'])) {
                throw new ParseError(sprintf('APP11 segment at offset %d has invalid JUMBF size field', $offset), 1332);
            }

            /** @var array{size:int} $sizeUnpack */
            $boxLength = $sizeUnpack['size'];
            if ($boxLength < 8) {
                throw new ParseError(
                    sprintf('APP11 segment at offset %d has invalid JUMBF box length %d', $offset, $boxLength),
                    1332,
                );
            }

            if ($position + $boxLength > $length) {
                throw new ParseError(sprintf('APP11 segment at offset %d has truncated JUMBF box', $offset), 1334);
            }

            return substr($payload, $position, $boxLength);
        }

        throw new ParseError(sprintf('APP11 segment at offset %d does not contain a JUMBF superbox', $offset), 1333);
    }

    /**
     * Traverses JUMBF boxes and collects XML/XMP payloads.
     *
     * @param string $boxStream Box stream beginning with one or more ISO-BMFF-style boxes.
     * @param int    $offset    APP11 marker offset for diagnostics.
     */
    private function collectApp11XmlPacketsFromBoxes(string $boxStream, int $offset): void
    {
        $length   = strlen($boxStream);
        $position = 0;

        while ($position + 8 <= $length) {
            $sizeUnpack = @unpack('Nsize', substr($boxStream, $position, 4));
            if (($sizeUnpack === false) || !isset($sizeUnpack['size'])) {
                throw new ParseError(sprintf('APP11 segment at offset %d has invalid JUMBF child size field', $offset), 1332);
            }

            /** @var array{size:int} $sizeUnpack */
            $boxLength = $sizeUnpack['size'];
            if ($boxLength < 8) {
                throw new ParseError(
                    sprintf('APP11 segment at offset %d has invalid JUMBF child box length %d', $offset, $boxLength),
                    1332,
                );
            }

            if ($position + $boxLength > $length) {
                throw new ParseError(sprintf('APP11 segment at offset %d has truncated JUMBF child box', $offset), 1334);
            }

            $boxType    = substr($boxStream, $position + 4, 4);
            $boxPayload = substr($boxStream, $position + 8, $boxLength - 8);

            if ($boxType === 'jumb') {
                $this->collectApp11XmlPacketsFromBoxes($boxPayload, $offset);
            } elseif ($boxType === 'xml ' || $boxType === 'bidb') {
                $candidate = $this->extractApp11XmlPacketCandidate($boxPayload);
                if ($candidate !== null) {
                    $this->appendXmpPacket($candidate);
                }
            }

            $position += $boxLength;
        }

        if ($position !== $length) {
            throw new ParseError(sprintf('APP11 segment at offset %d has trailing JUMBF bytes', $offset), 1334);
        }
    }

    /**
     * Extracts XML/XMP packet text from a JUMBF payload when recognizable.
     *
     * @param string $payload Raw JUMBF content payload.
     *
     * @return string|null XML/XMP packet text or null when not recognized.
     */
    private function extractApp11XmlPacketCandidate(string $payload): ?string
    {
        if (str_starts_with($payload, self::XMP_SIGNATURE)) {
            return substr($payload, strlen(self::XMP_SIGNATURE));
        }

        if (!str_contains($payload, '<')) {
            return null;
        }

        if (
            !str_contains($payload, '<?xml')
            && !str_contains($payload, '<x:xmpmeta')
            && !str_contains($payload, '<rdf:RDF')
        ) {
            return null;
        }

        $start = strpos($payload, '<');
        if ($start === false) {
            return null;
        }

        $candidate = trim(substr($payload, $start));

        return $candidate !== '' ? $candidate : null;
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

        // GH-922: validate audio version compatibility per EXIF 3.0 §5.2
        if ($major !== 1) {
            throw new ParseError(
                sprintf('Audio segment at offset %d uses unsupported major version %d', $offset, $major),
                1452,
            );
        }

        $format   = ord($payload[$signatureLength + 2]);
        $channels = ord($payload[$signatureLength + 3]);

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

        // GH-913: format-aware sampling rate validation per EXIF 3.0 §5.4.1
        $allowedSampleRates = match ($format) {
            self::AUDIO_FORMAT_PCM       => [8_000, 11_025, 22_050, 32_000, 44_100, 48_000, 96_000, 192_000],
            self::AUDIO_FORMAT_MU_LAW    => [8_000],
            self::AUDIO_FORMAT_IMA_ADPCM => [8_000, 11_025, 22_050, 44_100],
            default                      => [],
        };

        if (!in_array($sampleRate, $allowedSampleRates, true)) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported sample rate %d', $offset, $sampleRate), 1273);
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

        // GH-914: allow PCM 24-bit sample size per EXIF 3.0 §5.4.2
        if ($format === self::AUDIO_FORMAT_PCM && !in_array($bitDepth, [8, 16, 24], true)) {
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
     * EXIF 3.0 §4.7.3.1 requires APP2 ordering as Contents List first, then Stream Data.
     * EXIF 3.0 §4.7.3.4 and §4.7.3.5 define field-level structures for both segment bodies.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleFlashPixSegment(string $payload, int $offset): void
    {
        $body = $this->extractFlashPixBody($payload, $offset);

        if (!$this->flashPixContentsSeen) {
            $this->parseFlashPixContentsList($body, $offset);
            $this->flashPixContentsSeen = true;

            return;
        }

        $this->parseFlashPixStreamData($body, $offset);
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
     *
     * @return string
     */
    private function extractFlashPixBody(string $payload, int $offset): string
    {
        $signatureLength = strlen(self::FPXR_SIGNATURE);
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
    private function parseFlashPixContentsList(string $body, int $offset): void
    {
        if (strlen($body) < 2) {
            throw new ParseError(sprintf('FlashPix contents list at offset %d is too short', $offset), 1282);
        }

        $entryCount = (ord($body[0]) << 8) | ord($body[1]);

        if ($entryCount > self::FLASHPIX_MAX_CONTENT_ENTRIES) {
            throw new ParseError(
                sprintf(
                    'FlashPix contents list at offset %d has too many entries (%d)',
                    $offset,
                    $entryCount,
                ),
                1306,
            );
        }

        $cursor                 = 2;
        $length                 = strlen($body);
        $this->flashPixContents = [];

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

            [$name, $cursor] = $this->parseFlashPixName($body, $cursor, $offset, $index);

            if ($name[0] !== '/') {
                throw new ParseError(
                    sprintf(
                        'FlashPix contents entry %d at offset %d has invalid name prefix',
                        $index,
                        $offset,
                    ),
                    1309,
                );
            }

            $isStorage = $entitySize === self::FLASHPIX_STORAGE_ENTITY_SIZE;
            if (!$isStorage && $entitySize > self::FLASHPIX_MAX_STREAM_SIZE) {
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

            $this->flashPixContents[$index] = [
                'size'        => $entitySize,
                'defaultByte' => $defaultByte,
                'isStorage'   => $isStorage,
            ];
        }

        if ($cursor !== $length) {
            throw new ParseError(sprintf('FlashPix contents list at offset %d has trailing bytes', $offset), 1312);
        }
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
    private function parseFlashPixName(string $body, int $cursor, int $offset, int $index): array
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
            throw new ParseError(
                sprintf(
                    'FlashPix contents entry %d at offset %d has empty name',
                    $index,
                    $offset,
                ),
                1314,
            );
        }

        $decoded = iconv('UTF-16LE', 'UTF-8', $nameBytes);
        if ($decoded === false) {
            throw new ParseError(
                sprintf(
                    'FlashPix contents entry %d at offset %d has invalid UTF-16LE name',
                    $index,
                    $offset,
                ),
                1315,
            );
        }

        return [$decoded, $cursor];
    }

    /**
     * Parses one FPXR Stream Data segment and records validated stream chunks.
     *
     * EXIF 3.0 §4.7.3.5:
     * - index into contents list (0-based)
     * - offset into full stream
     * - remaining bytes are stream data
     *
     * @param string $body   FPXR segment body without signature.
     * @param int    $offset Marker offset used for diagnostics.
     */
    private function parseFlashPixStreamData(string $body, int $offset): void
    {
        if (!$this->flashPixContentsSeen) {
            throw new ParseError(sprintf('FlashPix stream data at offset %d appears before contents list', $offset), 1316);
        }

        if (strlen($body) < 6) {
            throw new ParseError(sprintf('FlashPix stream data at offset %d is too short', $offset), 1317);
        }

        $index        = (ord($body[0]) << 8) | ord($body[1]);
        $streamOffset = (ord($body[2]) << 24)
            | (ord($body[3]) << 16)
            | (ord($body[4]) << 8)
            | ord($body[5]);
        $data = substr($body, 6);

        if (!array_key_exists($index, $this->flashPixContents)) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d has invalid contents-list index %d',
                    $offset,
                    $index,
                ),
                1319,
            );
        }

        $entry = $this->flashPixContents[$index];
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

        if (($this->flashPixLastStreamIndex !== null) && ($index < $this->flashPixLastStreamIndex)) {
            throw new ParseError(
                sprintf(
                    'FlashPix stream data at offset %d breaks contents-list order',
                    $offset,
                ),
                1322,
            );
        }

        $this->flashPixLastStreamIndex = $index;

        $chunkLength = strlen($data);
        if ($chunkLength === 0) {
            return;
        }

        $start = $streamOffset;
        $end   = $streamOffset + $chunkLength;

        if (!array_key_exists($index, $this->flashPixRanges)) {
            $this->flashPixRanges[$index] = [];
        }

        foreach ($this->flashPixRanges[$index] as $range) {
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

        $this->flashPixRanges[$index][] = ['start' => $start, 'end' => $end];

        if (!array_key_exists($index, $this->flashPixChunks)) {
            $this->flashPixChunks[$index] = [];
        }

        $this->flashPixChunks[$index][] = ['offset' => $start, 'data' => $data];
    }

    /**
     * Materialises validated FlashPix stream chunks into full stream byte strings.
     *
     * Gaps in stream data are filled with the declared entry default byte
     * (EXIF 3.0 §4.7.3.4 / §4.7.3.5).
     */
    private function finaliseFlashPixStreams(): void
    {
        $this->flashPixStreams = [];

        foreach ($this->flashPixContents as $index => $entry) {
            if ($entry['isStorage']) {
                continue;
            }

            if (!array_key_exists($index, $this->flashPixChunks)) {
                continue;
            }

            $chunks = $this->flashPixChunks[$index];
            if ($chunks === []) {
                continue;
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

            $this->flashPixStreams[$index] = $assembled;
        }

        if ($this->flashPixStreams !== []) {
            ksort($this->flashPixStreams);
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
