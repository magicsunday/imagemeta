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
final class JpegParser implements JpegParserInterface
{
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

    /**
     * Maximum APP payload length implied by JPEG 16-bit segment length semantics.
     *
     * JPEG segment length includes its own two-byte length field, so payload is
     * bounded to 65535 - 2 = 65533 bytes.
     */
    private const int MAX_JPEG_APP_PAYLOAD_BYTES = 65_533;

    private bool $parsed = false;

    /** @var list<string> */
    private array $exifBlobs = [];

    private ?int $firstExifApp1Offset = null;

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

    /** @var array<int, string> */
    private array $flashPixStreams = [];

    /** @var list<string> */
    private array $mpfSegments = [];

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

    private ExtendedXmpAssembler $extendedXmpAssembler;

    private FlashPixStreamAssembler $flashPixAssembler;

    private JumbfTransportParser $jumbfParser;

    /**
     * Initialises the extractor with a seekable stream.
     *
     * @param Stream           $stream Stream representing the JPEG binary stream.
     * @param JpegParserConfig $config Parser limit configuration.
     */
    public function __construct(private readonly Stream $stream, private readonly JpegParserConfig $config = new JpegParserConfig())
    {
        $this->markerHandlerRegistry = $this->createDefaultMarkerHandlerRegistry();
        $this->extendedXmpAssembler  = new ExtendedXmpAssembler(
            $config->extendedXmpGuidLength,
            $this->appendXmpPacket(...),
        );
        $this->flashPixAssembler = new FlashPixStreamAssembler(
            $config->flashPixMaxContentEntries,
            $config->flashPixMaxStreamSize,
        );
        $this->jumbfParser = new JumbfTransportParser($this->appendXmpPacket(...));
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

        $this->exifBlobs            = [];
        $this->xmpPackets           = [];
        $this->extendedXmpAssembler = new ExtendedXmpAssembler(
            $this->config->extendedXmpGuidLength,
            $this->appendXmpPacket(...),
        );
        $this->iccSegments       = [];
        $this->iccSequence       = [];
        $this->iccExpectedCount  = null;
        $this->iccProfile        = null;
        $this->jumbfParser       = new JumbfTransportParser($this->appendXmpPacket(...));
        $this->flashPixAssembler = new FlashPixStreamAssembler(
            $this->config->flashPixMaxContentEntries,
            $this->config->flashPixMaxStreamSize,
        );
        $this->flashPixStreams        = [];
        $this->mpfSegments            = [];
        $this->mpfDocument            = null;
        $this->audioStreams           = [];
        $this->iptcPayloads           = [];
        $this->xmpPacketHashes        = [];
        $this->firstExifApp1Offset    = null;
        $this->frameBitsPerSample     = null;
        $this->frameComponentSampling = null;
        $this->frameYCbCrSubSampling  = null;
        $this->frameLines             = null;
        $this->frameSamplesPerLine    = null;

        $seenExifApp1                = false;
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

            // ITU-T T.81 §B.2.2: APP markers are "miscellaneous" markers that may
            // appear alongside DQT/DHT/DRI in any order before SOS.  EXIF 3.0 §4.7
            // only constrains APP11 ordering relative to structural markers; non-Exif
            // APP markers (APP0/JFIF, APP13/IPTC, APP14/Adobe) are tolerated here.
            // The APP11-after-structural check is enforced separately below.

            // ITU-T T.81 §B.2.4.1: DQT, DHT, and DRI are "tables/miscellaneous"
            // markers with zero-or-more repetitions.  Multiple segments are valid
            // (e.g. one DQT per quantization table).  Record first occurrence for
            // validateMandatoryExifPreScanMarkers() but accept duplicates.
            if ($marker === Marker::DQT) {
                $firstDqtOffset ??= $offset;
            }

            if ($marker === Marker::DHT) {
                $firstDhtOffset ??= $offset;
            }

            if ($marker === Marker::DRI) {
                $firstDriOffset ??= $offset;
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

                    $seenExifApp1 = true;
                } elseif (
                    ($marker === Marker::APP2)
                    && $this->isExifApp2ExtensionPayload($payload)
                ) {
                    // EXIF 3.0 §4.7.3: APP2 Exif extension must follow APP1 Exif.
                    // Non-Exif APPn/COM markers are not governed by Exif and are
                    // tolerated before APP1 (JFIF APP0, IPTC APP13, Adobe APP14, etc.).
                    $firstApp2BeforeExifOffset ??= $offset;
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
                $this->jumbfParser->handleSegment($payload, $offset);

                continue;
            }

            if ($marker === Marker::SOF0 || $marker === Marker::SOF2) {
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

        $this->extendedXmpAssembler->finalise();
        $this->jumbfParser->finalise();
        $this->flashPixAssembler->finalise();
        $this->flashPixStreams = $this->flashPixAssembler->getStreams();

        if ($this->mpfSegments !== []) {
            $payload = implode('', $this->mpfSegments);

            try {
                $this->mpfDocument = (new MpfParser())->parse($payload);
            } catch (ParseError) {
                // MPF (Multi-Picture Format) is optional supplementary
                // metadata.  A malformed MPF APP2 segment must not prevent
                // extraction of primary EXIF, XMP, and ICC data.
                $this->mpfDocument = null;
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

            // Unexpected marker in scan data — treat as end-of-scan
            // (Postel's Law: corrupted files may contain stray markers).
            return;
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
            $guid   = $this->extendedXmpAssembler->extractGuidFromPacket($packet, $offset);

            if ($guid !== null) {
                $this->extendedXmpAssembler->addBasePacket($packet, $guid, $offset);

                return;
            }

            $this->appendXmpPacket($packet);
        } elseif (str_starts_with($payload, ExtendedXmpAssembler::SIGNATURE)) {
            $this->extendedXmpAssembler->handleSegment($payload, $offset);
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
            $this->flashPixAssembler->handleSegment($payload, $offset);
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

        // Sequence count must not be zero
        if ($sequenceCount === 0) {
            throw new ParseError(
                sprintf('ICC segment at offset %d has zero sequence count', $offset),
                1301,
            );
        }

        // Sequence number must be in range 1..sequenceCount
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

        // All chunks must agree on the total count
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

        // Reject duplicate sequence numbers
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

        // Validate audio version compatibility per EXIF 3.0 §5.2
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

        // Format-aware sampling rate validation per EXIF 3.0 §5.4.1
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

        // Allow PCM 24-bit sample size per EXIF 3.0 §5.4.2
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

        // Non-empty IMA-ADPCM payload with dwSampleLength=0 is semantically inconsistent
        if ($format === self::AUDIO_FORMAT_IMA_ADPCM && $sampleCount === 0 && $data !== '') {
            throw new ParseError(sprintf('Audio segment at offset %d has non-empty IMA-ADPCM payload with zero sample count', $offset), 1280);
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

        $this->mpfSegments[] = substr($payload, $signatureLength);
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

        $derivedSubSampling = $this->deriveYCbCrSubSampling($components);

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

        // EXIF 3.0 §4.6.5.1.12 lists only [2,1] (4:2:2) and [2,2] (4:2:0)
        // as writer-side requirements.  ITU-T T.81 §B.2.2 allows arbitrary
        // sampling factors including [1,1] (4:4:4 — full chroma resolution).
        // Professional cameras and image editors legitimately produce 4:4:4
        // JPEGs with EXIF metadata.
        $legalValues = [
            [1, 1],
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
