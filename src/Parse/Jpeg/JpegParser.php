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
use function max;
use function min;
use function ord;
use function preg_match;
use function range;
use function sha1;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
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
final class JpegParser implements JpegParserInterface
{
    /**
     * Signatures identifying metadata-bearing APP segments as defined by the
     * Exif JPEG recording rules (EXIF 3.0 §4.7.2).
     */
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string EXTENDED_XMP_SIGNATURE = "http://ns.adobe.com/xmp/extension/\0";

    private const int EXTENDED_XMP_HEADER_LENGTH = 8;

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

    private const int APP11_TRANSPORT_HEADER_LENGTH = 10;

    private const int APP11_MAX_SEQUENCE_NUMBER = 65_535;

    private bool $parsed = false;

    /** @var list<string> */
    private array $exifBlobs = [];

    private ?int $firstExifApp1Offset = null;

    /** @var list<string> */
    private array $xmpPackets = [];

    /** @var array<string, bool> */
    private array $xmpPacketHashes = [];

    /** @var list<array{packet:string, guid:string, offset:int}> */
    private array $extendedXmpBasePackets = [];

    /** @var array<string, list<array{offset:int, length:int, data:string, segmentOffset:int}>> */
    private array $extendedXmpChunks = [];

    /** @var array<string, int> */
    private array $extendedXmpTotalLength = [];

    /** @var array<string, int> */
    private array $extendedXmpFirstOffset = [];

    /** @var list<string> */
    private array $iccSegments = [];

    /** @var array<int, string> */
    private array $iccSequence = [];

    private ?int $iccExpectedCount = null;

    private ?string $iccProfile = null;

    /** @var array<int, array<int, string>> */
    private array $app11Sequence = [];

    /** @var array<int, string> */
    private array $app11Identifier = [];

    /** @var array<int, int> */
    private array $app11FirstOffset = [];

    /** @var array<int, array{size:int, defaultByte:int, isStorage:bool}> */
    private array $flashPixContents = [];

    /** @var array<int, list<array{offset:int, data:string}>> */
    private array $flashPixChunks = [];

    /** @var array<int, list<array{start:int, end:int}>> */
    private array $flashPixRanges = [];

    /** @var array<int, int> */
    private array $flashPixSequenceExpectedCount = [];

    /** @var array<int, array<int, bool>> */
    private array $flashPixSequenceSeen = [];

    /** @var array<int, int> */
    private array $flashPixSequenceFirstOffset = [];

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

    private readonly MarkerHandlerRegistry $markerHandlerRegistry;

    /**
     * Initialises the extractor with a seekable stream.
     *
     * @param Stream           $stream Stream representing the JPEG binary stream.
     * @param JpegParserConfig $config Parser limit configuration.
     */
    public function __construct(private readonly Stream $stream, private readonly JpegParserConfig $config = new JpegParserConfig())
    {
        $this->markerHandlerRegistry = $this->createDefaultMarkerHandlerRegistry();
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
     * payloads. EXIF 3.0 §4.7 (Table 2) requires an EOI marker to terminate the JPEG
     * bitstream after scan data.
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

        $this->exifBlobs                     = [];
        $this->xmpPackets                    = [];
        $this->extendedXmpBasePackets        = [];
        $this->extendedXmpChunks             = [];
        $this->extendedXmpTotalLength        = [];
        $this->extendedXmpFirstOffset        = [];
        $this->iccSegments                   = [];
        $this->iccSequence                   = [];
        $this->iccExpectedCount              = null;
        $this->iccProfile                    = null;
        $this->app11Sequence                 = [];
        $this->app11Identifier               = [];
        $this->app11FirstOffset              = [];
        $this->flashPixContents              = [];
        $this->flashPixChunks                = [];
        $this->flashPixRanges                = [];
        $this->flashPixSequenceExpectedCount = [];
        $this->flashPixSequenceSeen          = [];
        $this->flashPixSequenceFirstOffset   = [];
        $this->flashPixContentsSeen          = false;
        $this->flashPixLastStreamIndex       = null;
        $this->flashPixStreams               = [];
        $this->mpfSegments                   = [];
        $this->mpfFirstOffset                = null;
        $this->mpfDocument                   = null;
        $this->audioStreams                  = [];
        $this->iptcPayloads                  = [];
        $this->xmpPacketHashes               = [];
        $this->firstExifApp1Offset           = null;
        $this->frameBitsPerSample            = null;
        $this->frameComponentSampling        = null;
        $this->frameYCbCrSubSampling         = null;
        $this->frameLines                    = null;
        $this->frameSamplesPerLine           = null;

        $seenExifApp1                = false;
        $markersBeforeExifApp1       = 0;
        $firstMarkerBeforeExifOffset = null;
        $firstApp2BeforeExifOffset   = null;
        $seenApp1OrApp2              = false;
        $seenApp11                   = false;
        $firstStructuralMarker       = null;
        $firstStructuralMarkerOffset = null;
        $firstDqtOffset              = null;
        $firstDhtOffset              = null;
        $firstDriOffset              = null;
        $firstSofOffset              = null;

        while (true) {
            [$marker, $offset] = $this->nextMarkerWithOffset(false);

            if ($marker === Marker::EOI) {
                if ($seenExifApp1) {
                    throw new ParseError(
                        sprintf(
                            'EXIF APP1 marker requires SOS before EOI; EOI marker found at offset %d without SOS marker',
                            $offset,
                        ),
                        1487,
                    );
                }

                break;
            }

            if ($marker === Marker::SOS) {
                if ($seenExifApp1) {
                    $this->validateMandatoryExifPreScanMarkers(
                        $firstDqtOffset,
                        $firstDhtOffset,
                        $firstSofOffset,
                        $offset,
                    );
                }

                $this->requireEoiAfterSos($offset, $firstDriOffset);
                break; // EXIF 3.0 §4.7.1 restricts metadata APP markers to precede the first SOS.
            }

            if ($marker === Marker::TEM) {
                throw new ParseError(
                    sprintf(
                        'TEM marker at offset %d is not allowed before SOS marker in strict EXIF JPEG mode',
                        $offset,
                    ),
                    1502,
                );
            }

            if (($marker >= Marker::RST_FIRST) && ($marker <= Marker::RST_LAST)) {
                throw new ParseError(
                    sprintf(
                        'Restart marker 0x%02X at offset %d is not allowed before SOS marker',
                        $marker,
                        $offset,
                    ),
                    1499,
                );
            }

            // EXIF 3.0 §4.5.4: SOI is a stand-alone marker that shall appear
            // exactly once at the beginning of the JPEG stream.
            if ($marker === Marker::SOI) {
                throw new ParseError(
                    sprintf('duplicate SOI marker at offset %d', $offset),
                    1507,
                );
            }

            $isAppSegment  = $marker >= Marker::APP_FIRST && $marker <= Marker::APP_LAST;
            $segmentLength = $this->readSegmentLength($marker, $offset, $isAppSegment);
            $payloadLength = $segmentLength - 2;
            $payload       = $this->readSegmentPayload($marker, $offset, $payloadLength);

            if (
                $isAppSegment
                && ($marker !== Marker::APP11)
                && ($firstStructuralMarker !== null)
                && ($firstStructuralMarkerOffset !== null)
            ) {
                throw new ParseError(
                    sprintf(
                        'APP marker 0x%02X at offset %d appears after structural marker 0x%02X at offset %d',
                        $marker,
                        $offset,
                        $firstStructuralMarker,
                        $firstStructuralMarkerOffset,
                    ),
                    1340,
                );
            }

            if ($marker === Marker::DQT) {
                if ($firstDqtOffset !== null) {
                    throw new ParseError(
                        sprintf('DQT marker at offset %d duplicates DQT marker at offset %d', $offset, $firstDqtOffset),
                        1341,
                    );
                }

                $firstDqtOffset = $offset;
            }

            if ($marker === Marker::DHT) {
                if ($firstDhtOffset !== null) {
                    throw new ParseError(
                        sprintf('DHT marker at offset %d duplicates DHT marker at offset %d', $offset, $firstDhtOffset),
                        1342,
                    );
                }

                $firstDhtOffset = $offset;
            }

            if ($marker === Marker::DRI) {
                if ($firstDriOffset !== null) {
                    throw new ParseError(
                        sprintf('DRI marker at offset %d duplicates DRI marker at offset %d', $offset, $firstDriOffset),
                        1343,
                    );
                }

                $firstDriOffset = $offset;
            }

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

            if ($this->markerHandlerRegistry->supports($marker)) {
                $this->markerHandlerRegistry->dispatch($marker, $this->stream, $payload, $offset);

                continue;
            }

            if ($marker === Marker::APP11) {
                $this->handleApp11($payload, $offset);

                continue;
            }

            if ($marker === Marker::SOF2) {
                throw new ParseError(
                    sprintf(
                        'Progressive SOF2 marker at offset %d is not allowed in strict EXIF JPEG mode per EXIF 3.0 §4.8.1.',
                        $offset,
                    ),
                    1486,
                );
            }

            if ($marker === Marker::SOF0) {
                // EXIF 3.0 §4.7 Table 2 defines one frame-header declaration in the
                // marker flow before SOS; additional SOF markers are non-conformant.
                if ($firstSofOffset !== null) {
                    throw new ParseError(
                        sprintf(
                            'SOF marker at offset %d duplicates SOF marker at offset %d before SOS',
                            $offset,
                            $firstSofOffset,
                        ),
                        1504,
                    );
                }

                $firstSofOffset = $offset;
                $this->handleStartOfFrame($marker, $payload, $offset, $seenExifApp1);
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

        $this->finaliseExtendedXmpPackets();
        $this->finaliseApp11Segments();
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
     * Creates the default APP marker-handler strategy registry.
     */
    private function createDefaultMarkerHandlerRegistry(): MarkerHandlerRegistry
    {
        return new MarkerHandlerRegistry([
            new ExifSegmentHandler($this->handleApp1(...)),
            new XmpSegmentHandler($this->handleApp1(...)),
            new IccProfileHandler($this->handleApp2(...)),
            new AudioStreamHandler($this->handleApp2(...)),
            new MpfDocumentHandler($this->handleApp2(...)),
            new FlashPixHandler($this->handleApp2(...)),
            new IptcSegmentHandler(function (string $payload, int $offset): void {
                $this->handleApp13($payload);
            }),
        ]);
    }

    /**
     * Validates EXIF-mandated pre-scan marker groups before SOS.
     *
     * EXIF 3.0 §4.7 (Table 2) requires DQT, DHT, and SOF marker groups before SOS
     * when the stream advertises Exif APP1 metadata.
     *
     * @param int|null $dqtOffset Offset of DQT marker when present.
     * @param int|null $dhtOffset Offset of DHT marker when present.
     * @param int|null $sofOffset Offset of SOF marker when present.
     * @param int      $sosOffset Offset of SOS marker.
     */
    private function validateMandatoryExifPreScanMarkers(
        ?int $dqtOffset,
        ?int $dhtOffset,
        ?int $sofOffset,
        int $sosOffset,
    ): void {
        if ($dqtOffset === null) {
            throw new ParseError(
                sprintf(
                    'EXIF APP1 marker requires DQT before SOS; SOS marker at offset %d has no preceding DQT marker',
                    $sosOffset,
                ),
                1488,
            );
        }

        if ($dhtOffset === null) {
            throw new ParseError(
                sprintf(
                    'EXIF APP1 marker requires DHT before SOS; SOS marker at offset %d has no preceding DHT marker',
                    $sosOffset,
                ),
                1489,
            );
        }

        if ($sofOffset === null) {
            throw new ParseError(
                sprintf(
                    'EXIF APP1 marker requires SOF before SOS; SOS marker at offset %d has no preceding SOF marker',
                    $sosOffset,
                ),
                1490,
            );
        }
    }

    /**
     * Validates that scan data following SOS is terminated by an EOI marker.
     *
     * EXIF 3.0 §4.7 (Table 2) requires EOI as the JPEG stream terminator and
     * requires restart markers in scan data when DRI is declared.
     *
     * @param int      $sosOffset Offset where the SOS marker starts.
     * @param int|null $driOffset Offset of the DRI marker when present.
     */
    private function requireEoiAfterSos(int $sosOffset, ?int $driOffset): void
    {
        $scanHeaderLength  = $this->readSegmentLength(Marker::SOS, $sosOffset, false);
        $scanHeaderPayload = $this->readSegmentPayload(Marker::SOS, $sosOffset, $scanHeaderLength - 2);
        $this->validateSosHeader($scanHeaderPayload, $sosOffset);
        $hasRestartMarker = false;

        while (true) {
            try {
                [$marker, $markerOffset] = $this->nextMarkerWithOffset();
            } catch (BoundsError $exception) {
                throw new ParseError(
                    sprintf(
                        'JPEG stream ended after SOS marker at offset %d without EOI marker',
                        $sosOffset,
                    ),
                    1484,
                    $exception,
                );
            }

            if (($marker >= Marker::RST_FIRST) && ($marker <= Marker::RST_LAST)) {
                $hasRestartMarker = true;
                continue;
            }

            if ($marker === Marker::EOI) {
                if (($driOffset !== null) && !$hasRestartMarker) {
                    throw new ParseError(
                        sprintf(
                            'JPEG stream declares DRI marker at offset %d but scan data after SOS at offset %d contains no restart markers',
                            $driOffset,
                            $sosOffset,
                        ),
                        1485,
                    );
                }

                return;
            }

            throw new ParseError(
                sprintf(
                    'Unexpected marker 0x%02X at offset %d in scan data after SOS marker at offset %d',
                    $marker,
                    $markerOffset,
                    $sosOffset,
                ),
                1503,
            );
        }
    }

    /**
     * Validates SOS header structure and SOF component consistency.
     *
     * EXIF 3.0 §4.7 requires an SOS marker segment whose component selectors
     * align with the previously declared SOF frame components.
     *
     * @param string $payload   Raw SOS header payload (without marker and length field).
     * @param int    $sosOffset Offset where the SOS marker starts.
     */
    private function validateSosHeader(string $payload, int $sosOffset): void
    {
        $payloadLength = strlen($payload);
        if ($payloadLength < 6) {
            throw new ParseError(
                sprintf('SOS marker at offset %d is too short', $sosOffset),
                1498,
            );
        }

        $componentCount = ord($payload[0]);
        if ($componentCount === 0) {
            throw new ParseError(
                sprintf('SOS marker at offset %d declares zero components', $sosOffset),
                1498,
            );
        }

        $expectedLength = 1 + ($componentCount * 2) + 3;
        if ($payloadLength !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'SOS marker at offset %d declares %d components but payload length is %d bytes (expected %d)',
                    $sosOffset,
                    $componentCount,
                    $payloadLength,
                    $expectedLength,
                ),
                1498,
            );
        }

        if ($this->frameComponentSampling === null) {
            return;
        }

        $frameComponentIds = array_keys($this->frameComponentSampling);
        if ($componentCount !== count($frameComponentIds)) {
            throw new ParseError(
                sprintf(
                    'SOS marker at offset %d has component count %d but SOF declares component count %d',
                    $sosOffset,
                    $componentCount,
                    count($frameComponentIds),
                ),
                1497,
            );
        }

        $seenSelectors = [];
        $index         = 1;

        for ($i = 0; $i < $componentCount; ++$i) {
            $componentSelector = ord($payload[$index]);
            if (isset($seenSelectors[$componentSelector])) {
                throw new ParseError(
                    sprintf(
                        'SOS marker at offset %d contains duplicate component selector %d',
                        $sosOffset,
                        $componentSelector,
                    ),
                    1496,
                );
            }

            if (!array_key_exists($componentSelector, $this->frameComponentSampling)) {
                throw new ParseError(
                    sprintf(
                        'SOS marker at offset %d references unknown component selector %d not declared in SOF',
                        $sosOffset,
                        $componentSelector,
                    ),
                    1495,
                );
            }

            $seenSelectors[$componentSelector] = true;
            $index += 2;
        }
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
     * @param bool $allowInterveningBytes Whether non-marker bytes may appear before the next marker introducer.
     *
     * @return array{0: int, 1: int}
     */
    private function nextMarkerWithOffset(bool $allowInterveningBytes = true): array
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
            if ($payloadLength > $this->config->maxAppSegmentSize) {
                // EXIF 3.0 §4.5.2 keeps APP1/APP2 payloads within the JPEG
                // 64 KiB segment budget; this wider ceiling rejects obviously pathological blobs
                // before the TIFF parser is invoked.
                throw new ParseError(
                    sprintf(
                        'APP segment 0x%02X at offset %d exceeds maximum payload of %d bytes',
                        $marker,
                        $offset,
                        $this->config->maxAppSegmentSize,
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
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleApp1(string $payload, int $offset): void
    {
        if (str_starts_with($payload, self::EXIF_SIGNATURE)) {
            if ($this->firstExifApp1Offset !== null) {
                throw new ParseError(
                    sprintf(
                        'Duplicate Exif APP1 marker at offset %d; first Exif APP1 marker was at offset %d',
                        $offset,
                        $this->firstExifApp1Offset,
                    ),
                    1501,
                );
            }

            $this->firstExifApp1Offset = $offset;

            // EXIF 3.0 §4.7.2 requires APP1 Exif data to start with "Exif\0\0"
            // followed by a valid TIFF header (byte order + magic number).
            $tiffData = substr($payload, strlen(self::EXIF_SIGNATURE));
            $this->validateApp1TiffHeader($tiffData);
            $this->exifBlobs[] = $tiffData;
        } elseif (str_starts_with($payload, self::XMP_SIGNATURE)) {
            $packet = substr($payload, strlen(self::XMP_SIGNATURE));
            $guid   = $this->extractExtendedXmpGuidFromPacket($packet, $offset);

            if ($guid !== null) {
                $this->extendedXmpBasePackets[] = [
                    'packet' => $packet,
                    'guid'   => $guid,
                    'offset' => $offset,
                ];

                return;
            }

            $this->appendXmpPacket($packet);
        } elseif (str_starts_with($payload, self::EXTENDED_XMP_SIGNATURE)) {
            $this->handleExtendedXmpSegment($payload, $offset);
        }
    }

    /**
     * Parses and stores one ExtendedXMP APP1 chunk.
     *
     * Adobe XMP Storage in Files defines the JPEG APP1 extension container as:
     * signature + GUID + full-length + chunk-offset + chunk bytes.
     *
     * @param string $payload Raw APP1 payload containing extended XMP header fields.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    private function handleExtendedXmpSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::EXTENDED_XMP_SIGNATURE);
        $guidLength      = $this->config->extendedXmpGuidLength;
        $minimumLength   = $signatureLength + $guidLength + self::EXTENDED_XMP_HEADER_LENGTH;
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
        $lengthUnpack  = @unpack('Nlength', substr($payload, $lengthOffset, 4));
        $offsetUnpack  = @unpack('Noffset', substr($payload, $lengthOffset + 4, 4));
        $extendedChunk = substr($payload, $lengthOffset + self::EXTENDED_XMP_HEADER_LENGTH);

        if (($lengthUnpack === false) || !isset($lengthUnpack['length'])) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has invalid full-length field', $offset),
                1472,
            );
        }

        if (($offsetUnpack === false) || !isset($offsetUnpack['offset'])) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has invalid chunk-offset field', $offset),
                1473,
            );
        }

        /** @var array{length:int} $lengthUnpack */
        $totalLength = $lengthUnpack['length'];
        if ($totalLength <= 0) {
            throw new ParseError(
                sprintf('ExtendedXMP APP1 segment at offset %d has non-positive full length %d', $offset, $totalLength),
                1472,
            );
        }

        /** @var array{offset:int} $offsetUnpack */
        $chunkOffset = $offsetUnpack['offset'];
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

        if (!array_key_exists($guid, $this->extendedXmpTotalLength)) {
            $this->extendedXmpTotalLength[$guid] = $totalLength;
            $this->extendedXmpFirstOffset[$guid] = $offset;
            $this->extendedXmpChunks[$guid]      = [];
        } elseif ($this->extendedXmpTotalLength[$guid] !== $totalLength) {
            $firstOffset = $this->extendedXmpFirstOffset[$guid] ?? $offset;

            throw new ParseError(
                sprintf(
                    'ExtendedXMP GUID %s has inconsistent full length %d at offset %d (first seen %d at offset %d)',
                    $guid,
                    $totalLength,
                    $offset,
                    $this->extendedXmpTotalLength[$guid],
                    $firstOffset,
                ),
                1474,
            );
        }

        $this->extendedXmpChunks[$guid][] = [
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
    private function extractExtendedXmpGuidFromPacket(string $packet, int $offset): ?string
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
     * Reassembles ExtendedXMP chunks and merges them with referenced base packets.
     */
    private function finaliseExtendedXmpPackets(): void
    {
        if ($this->extendedXmpBasePackets === []) {
            return;
        }

        $requiredGuids = [];
        foreach ($this->extendedXmpBasePackets as $basePacket) {
            $requiredGuids[$basePacket['guid']] = true;
        }

        foreach (array_keys($this->extendedXmpChunks) as $guid) {
            if (!array_key_exists($guid, $requiredGuids)) {
                $offset = $this->extendedXmpFirstOffset[$guid] ?? 0;

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
        foreach ($this->extendedXmpBasePackets as $basePacket) {
            $guid = $basePacket['guid'];
            if (!array_key_exists($guid, $this->extendedXmpChunks)) {
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
                $assembledPayloads[$guid] = $this->assembleExtendedXmpPayload($guid, $basePacket['offset']);
            }

            $this->appendXmpPacket($basePacket['packet'] . $assembledPayloads[$guid]);
        }
    }

    /**
     * Validates and concatenates all ExtendedXMP chunks for one GUID.
     *
     * @param string $guid       ExtendedXMP GUID.
     * @param int    $baseOffset Base APP1 offset for diagnostics.
     *
     * @return string
     */
    private function assembleExtendedXmpPayload(string $guid, int $baseOffset): string
    {
        $chunks      = $this->extendedXmpChunks[$guid] ?? [];
        $totalLength = $this->extendedXmpTotalLength[$guid] ?? 0;
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

        $header         = $this->parseApp11TransportHeader($payload, $offset);
        $identifier     = $header['identifier'];
        $instanceNumber = $header['instance'];
        $sequenceNumber = $header['sequence'];
        $transportData  = $header['data'];

        if (!array_key_exists($instanceNumber, $this->app11Identifier)) {
            $this->app11Identifier[$instanceNumber]  = $identifier;
            $this->app11FirstOffset[$instanceNumber] = $offset;
        } elseif ($this->app11Identifier[$instanceNumber] !== $identifier) {
            throw new ParseError(
                sprintf(
                    'APP11 segment at offset %d has inconsistent instance metadata for instance %d',
                    $offset,
                    $instanceNumber,
                ),
                1337,
            );
        }

        if (!array_key_exists($instanceNumber, $this->app11Sequence)) {
            $this->app11Sequence[$instanceNumber] = [];
        }

        if (array_key_exists($sequenceNumber, $this->app11Sequence[$instanceNumber])) {
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

        $this->app11Sequence[$instanceNumber][$sequenceNumber] = $transportData;
    }

    /**
     * Parses the APP11 transport header and returns sequence metadata.
     *
     * EXIF 3.0 §4.7.5.3 defines the APP11 transport wrapper as identifier, box
     * instance number, packet sequence number, and payload bytes.
     *
     * @param string $payload Raw APP11 payload bytes.
     * @param int    $offset  APP11 marker offset for diagnostics.
     *
     * @return array{identifier:string, instance:int, sequence:int, data:string}
     */
    private function parseApp11TransportHeader(string $payload, int $offset): array
    {
        if (strlen($payload) < self::APP11_TRANSPORT_HEADER_LENGTH) {
            throw new ParseError(sprintf('APP11 segment at offset %d is too short', $offset), 1331);
        }

        $identifier = substr($payload, 0, 4);

        $instanceUnpack = @unpack('ninstance', substr($payload, 4, 2));
        if (($instanceUnpack === false) || !isset($instanceUnpack['instance'])) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has invalid instance number', $offset),
                1335,
            );
        }

        /** @var array{instance:int} $instanceUnpack */
        $instanceNumber = $instanceUnpack['instance'];
        if ($instanceNumber === 0) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has out-of-range instance number %d', $offset, $instanceNumber),
                1335,
            );
        }

        $sequenceUnpack = @unpack('Nsequence', substr($payload, 6, 4));
        if (($sequenceUnpack === false) || !isset($sequenceUnpack['sequence'])) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has invalid sequence number', $offset),
                1336,
            );
        }

        /** @var array{sequence:int} $sequenceUnpack */
        $sequenceNumber = $sequenceUnpack['sequence'];
        if (
            ($sequenceNumber === 0)
            || ($sequenceNumber > self::APP11_MAX_SEQUENCE_NUMBER)
        ) {
            throw new ParseError(
                sprintf('APP11 segment at offset %d has out-of-range sequence number %d', $offset, $sequenceNumber),
                1336,
            );
        }

        return [
            'identifier' => $identifier,
            'instance'   => $instanceNumber,
            'sequence'   => $sequenceNumber,
            'data'       => substr($payload, self::APP11_TRANSPORT_HEADER_LENGTH),
        ];
    }

    /**
     * Finalises APP11 transport streams by validating and reassembling chunks.
     *
     * EXIF 3.0 §4.7.5.1 and §4.7.5.3 define APP11 sequence metadata for
     * marker-segment merging when logically identical JUMBF data is split.
     */
    private function finaliseApp11Segments(): void
    {
        foreach ($this->app11Sequence as $instanceNumber => $sequenceChunks) {
            if ($sequenceChunks === []) {
                continue;
            }

            $offset      = $this->app11FirstOffset[$instanceNumber] ?? 0;
            $maxSequence = max(array_keys($sequenceChunks));

            for ($expectedSequence = 1; $expectedSequence <= $maxSequence; ++$expectedSequence) {
                if (!array_key_exists($expectedSequence, $sequenceChunks)) {
                    throw new ParseError(
                        sprintf(
                            'APP11 segment sequence is missing sequence number %d for instance %d (at offset %d)',
                            $expectedSequence,
                            $instanceNumber,
                            $offset,
                        ),
                        1339,
                    );
                }
            }

            ksort($sequenceChunks);
            $transportPayload = implode('', $sequenceChunks);
            $jumbfSuperbox    = $this->extractApp11JumbfSuperbox($transportPayload, $offset);
            $this->collectApp11XmlPacketsFromBoxes($jumbfSuperbox, $offset);
        }
    }

    /**
     * Extracts the first valid JUMBF superbox from APP11 transport payload data.
     *
     * @param string $payload Reassembled APP11 transport payload bytes.
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

        if ($format !== self::AUDIO_FORMAT_IMA_ADPCM) {
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

        if ($entryCount > $this->config->flashPixMaxContentEntries) {
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
            if (!$isStorage && $entitySize > $this->config->flashPixMaxStreamSize) {
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
     * - sequence number / sequence count for segment assembly
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

        if (strlen($body) < 10) {
            throw new ParseError(sprintf('FlashPix stream data at offset %d is too short', $offset), 1317);
        }

        $index          = (ord($body[0]) << 8) | ord($body[1]);
        $sequenceNumber = (ord($body[2]) << 8) | ord($body[3]);
        $sequenceCount  = (ord($body[4]) << 8) | ord($body[5]);
        $streamOffset   = (ord($body[6]) << 24)
            | (ord($body[7]) << 16)
            | (ord($body[8]) << 8)
            | ord($body[9]);
        $data = substr($body, 10);

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

        if (!array_key_exists($index, $this->flashPixSequenceExpectedCount)) {
            $this->flashPixSequenceExpectedCount[$index] = $sequenceCount;
            $this->flashPixSequenceSeen[$index]          = [];
            $this->flashPixSequenceFirstOffset[$index]   = $offset;
        } elseif ($this->flashPixSequenceExpectedCount[$index] !== $sequenceCount) {
            $firstOffset = $this->flashPixSequenceFirstOffset[$index] ?? $offset;

            throw new ParseError(
                sprintf(
                    'FlashPix stream entry %d has inconsistent sequence count %d at offset %d (expected %d from offset %d)',
                    $index,
                    $sequenceCount,
                    $offset,
                    $this->flashPixSequenceExpectedCount[$index],
                    $firstOffset,
                ),
                1481,
            );
        }

        if (array_key_exists($sequenceNumber, $this->flashPixSequenceSeen[$index])) {
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

        $this->flashPixSequenceSeen[$index][$sequenceNumber] = true;

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

        foreach ($this->flashPixSequenceExpectedCount as $index => $sequenceCount) {
            $seen = $this->flashPixSequenceSeen[$index] ?? [];

            for ($expected = 1; $expected <= $sequenceCount; ++$expected) {
                if (!array_key_exists($expected, $seen)) {
                    $firstOffset = $this->flashPixSequenceFirstOffset[$index] ?? 0;

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
     * Parses baseline start of frame markers to obtain sampling information.
     *
     * In strict EXIF mode (EXIF APP1 present), EXIF 3.0 §4.8.1 requires 8-bit YCbCr
     * baseline framing with three components (Y, Cb, Cr) and legal YCbCr subsampling.
     *
     * @param int    $marker            Marker code (SOF0).
     * @param string $payload           Raw SOF payload excluding the marker and length field.
     * @param int    $offset            Offset where the SOF marker begins.
     * @param bool   $strictExifProfile Whether strict EXIF SOF profile checks must be applied.
     */
    private function handleStartOfFrame(int $marker, string $payload, int $offset, bool $strictExifProfile): void
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

        if ($strictExifProfile && ($componentCount !== 3)) {
            throw new ParseError(
                sprintf(
                    'SOF marker 0x%02X at offset %d must declare exactly three components in strict EXIF mode',
                    $marker,
                    $offset,
                ),
                1492,
            );
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

            if (array_key_exists($componentId, $components)) {
                throw new ParseError(
                    sprintf(
                        'SOF marker 0x%02X at offset %d contains duplicate component identifier %d',
                        $marker,
                        $offset,
                        $componentId,
                    ),
                    1500,
                );
            }

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

        $bitsPerSample = ord($payload[0]);
        if ($strictExifProfile && ($bitsPerSample !== 8)) {
            throw new ParseError(
                sprintf(
                    'SOF marker 0x%02X at offset %d must use 8-bit sample precision in strict EXIF mode',
                    $marker,
                    $offset,
                ),
                1491,
            );
        }

        if ($strictExifProfile) {
            $componentIdentifiers = array_keys($components);
            sort($componentIdentifiers);

            if ($componentIdentifiers !== [1, 2, 3]) {
                throw new ParseError(
                    sprintf(
                        'SOF marker 0x%02X at offset %d must use YCbCr component identifiers 1/2/3 in strict EXIF mode',
                        $marker,
                        $offset,
                    ),
                    1493,
                );
            }
        }

        $derivedSubSampling = $this->deriveYCbCrSubSampling($components);
        if ($strictExifProfile && ($derivedSubSampling === null)) {
            throw new ParseError(
                sprintf(
                    'SOF marker 0x%02X at offset %d must derive EXIF YCbCr subsampling 4:2:2 or 4:2:0 in strict EXIF mode',
                    $marker,
                    $offset,
                ),
                1494,
            );
        }

        $this->frameBitsPerSample     = $bitsPerSample;
        $this->frameComponentSampling = $components;
        $this->frameYCbCrSubSampling  = $derivedSubSampling;
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
