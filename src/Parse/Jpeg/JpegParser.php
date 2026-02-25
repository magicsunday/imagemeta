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
use MagicSunday\ImageMeta\Model\Jpeg\Marker;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;

use function implode;
use function sprintf;
use function strlen;
use function substr;

/**
 * Parses JPEG streams to extract metadata-bearing APP segments.
 *
 * EXIF 3.0 §4.7.2 documents the APP1 encapsulation for Exif payloads; EXIF 3.0 §4.7.3
 * defines the audio APP2 layout.
 */
final class JpegParser implements JpegParserInterface
{
    /**
     * Signature identifying MP Index payloads inside APP2 markers (EXIF 3.0 §4.6.4).
     */
    private const string MPF_SIGNATURE = "MPF\0";

    private bool $parsed = false;

    /** @var array<int, string> */
    private array $flashPixStreams = [];

    /** @var list<string> */
    private array $mpfSegments = [];

    private ?MpfDocument $mpfDocument = null;

    /** @var list<string> */
    private array $iptcPayloads = [];

    private readonly MarkerHandlerRegistry $markerHandlerRegistry;

    private readonly JpegMarkerScanner $scanner;

    private readonly JpegFrameValidator $frameValidator;

    private readonly IccProfileAssembler $iccAssembler;

    private readonly JpegAudioSegmentParser $audioParser;

    private readonly JpegApp1Handler $app1Handler;

    private FlashPixStreamAssembler $flashPixAssembler;

    private JumbfTransportParser $jumbfParser;

    private ?int $firstSofOffset = null;

    /**
     * Initialises the extractor with a seekable stream.
     *
     * @param Stream           $stream Stream representing the JPEG binary stream.
     * @param JpegParserConfig $config Parser limit configuration.
     */
    public function __construct(private readonly Stream $stream, private readonly JpegParserConfig $config = new JpegParserConfig())
    {
        $this->scanner           = new JpegMarkerScanner($stream, $config);
        $this->frameValidator    = new JpegFrameValidator($this->scanner);
        $this->iccAssembler      = new IccProfileAssembler($config->maxIccProfileSize);
        $this->audioParser       = new JpegAudioSegmentParser();
        $this->app1Handler       = new JpegApp1Handler($config->extendedXmpGuidLength, $config->maxExtendedXmpSize);
        $this->flashPixAssembler = new FlashPixStreamAssembler(
            $config->flashPixMaxContentEntries,
            $config->flashPixMaxStreamSize,
            $config->maxFlashPixTotalSize,
        );
        $this->jumbfParser = new JumbfTransportParser($this->app1Handler->appendXmpPacket(...));

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

        return $this->app1Handler->getExifBlobs();
    }

    /**
     * Returns all discovered XMP packets in the order they appeared.
     *
     * @return list<string>
     */
    public function extractXmpPackets(): array
    {
        $this->parseIfNeeded();

        return $this->app1Handler->getXmpPackets();
    }

    /**
     * Returns the merged ICC profile when complete metadata segments were found.
     */
    public function getIccProfile(): ?string
    {
        $this->parseIfNeeded();

        return $this->iccAssembler->getProfile();
    }

    /**
     * Returns all ICC profile segments in the order encountered.
     *
     * @return list<string>
     */
    public function getIccSegments(): array
    {
        $this->parseIfNeeded();

        return $this->iccAssembler->getSegments();
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

        return $this->audioParser->getStreams();
    }

    /**
     * Returns the parsed MPF document or null when it is unavailable.
     *
     * Triggers lazy parsing via parseIfNeeded() if the stream has not been processed yet.
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

        return $this->frameValidator->getFrameBitsPerSample();
    }

    /**
     * Returns the frame height reported by the primary start of frame segment.
     */
    public function getFrameHeight(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameValidator->getFrameLines();
    }

    /**
     * Returns the frame width reported by the primary start of frame segment.
     */
    public function getFrameWidth(): ?int
    {
        $this->parseIfNeeded();

        return $this->frameValidator->getFrameSamplesPerLine();
    }

    /**
     * Returns the horizontal and vertical sampling factors for components identified in the SOF.
     *
     * @return array<int, array{horizontal:int, vertical:int}>|null
     */
    public function getFrameComponentSamplingFactors(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameValidator->getFrameComponentSampling();
    }

    /**
     * Returns the derived YCbCr subsampling factors inferred from the SOF component sampling.
     *
     * @return array{0:int,1:int}|null
     */
    public function getFrameYCbCrSubSampling(): ?array
    {
        $this->parseIfNeeded();

        return $this->frameValidator->getFrameYCbCrSubSampling();
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

        $this->resetParseState();

        try {
            while (true) {
                [$marker, $offset] = $this->scanner->nextMarkerWithOffset(false);

                if ($this->processMarkerSegment($marker, $offset)) {
                    break;
                }
            }
        } catch (BoundsError) {
            // Truncated stream — return whatever metadata was collected so far.
        }

        $this->finaliseParseResults();
        $this->parsed = true;
    }

    /**
     * Resets all mutable parse state for a fresh scan.
     */
    private function resetParseState(): void
    {
        $this->app1Handler->reset();
        $this->iccAssembler->reset();
        $this->audioParser->reset();
        $this->frameValidator->reset();
        $this->jumbfParser       = new JumbfTransportParser($this->app1Handler->appendXmpPacket(...));
        $this->flashPixAssembler = new FlashPixStreamAssembler(
            $this->config->flashPixMaxContentEntries,
            $this->config->flashPixMaxStreamSize,
            $this->config->maxFlashPixTotalSize,
        );
        $this->flashPixStreams = [];
        $this->mpfSegments     = [];
        $this->mpfDocument     = null;
        $this->iptcPayloads    = [];
        $this->firstSofOffset  = null;
    }

    /**
     * Processes a single marker encountered during the JPEG scan loop.
     *
     * @return bool True when the scan loop should terminate (EOI or SOS reached).
     */
    private function processMarkerSegment(int $marker, int $offset): bool
    {
        if ($marker === Marker::EOI) {
            return true;
        }

        if ($marker === Marker::SOS) {
            $this->frameValidator->validateSosSegment($offset);

            return true; // EXIF 3.0 §4.7.1 restricts metadata APP markers to precede the first SOS.
        }

        // TEM, RST, and duplicate SOI are stand-alone markers with no payload.
        // Tolerate them per Postel's Law — they carry no metadata.
        if ($marker === Marker::TEM) {
            return false;
        }

        if (($marker >= Marker::RST_FIRST) && ($marker <= Marker::RST_LAST)) {
            return false;
        }

        if ($marker === Marker::SOI) {
            return false;
        }

        $isAppSegment  = $marker >= Marker::APP_FIRST && $marker <= Marker::APP_LAST;
        $segmentLength = $this->scanner->readSegmentLength($marker, $offset, $isAppSegment);
        $payloadLength = $segmentLength - 2;
        $payload       = $this->scanner->readSegmentPayload($marker, $offset, $payloadLength);

        if ($this->markerHandlerRegistry->supports($marker)) {
            $this->markerHandlerRegistry->dispatch($marker, $this->stream, $payload, $offset);

            return false;
        }

        if ($marker === Marker::APP11) {
            $this->jumbfParser->handleSegment($payload, $offset);

            return false;
        }

        $this->processStartOfFrame($marker, $payload, $offset);

        return false;
    }

    /**
     * Handles SOF marker segments by validating uniqueness and delegating frame parsing.
     *
     * EXIF 3.0 §4.7 Table 2 defines one frame-header declaration in the
     * marker flow before SOS; additional SOF markers are non-conformant.
     */
    private function processStartOfFrame(int $marker, string $payload, int $offset): void
    {
        // ITU-T T.81 Table B.1 — all SOF markers (SOF0–SOF15) are valid frame headers
        if (!$this->frameValidator->isStartOfFrameMarker($marker)) {
            return;
        }

        if ($this->firstSofOffset !== null) {
            throw new ParseError(
                sprintf(
                    'SOF marker at offset %d duplicates SOF marker at offset %d before SOS',
                    $offset,
                    $this->firstSofOffset,
                ),
                1504,
            );
        }

        $this->firstSofOffset = $offset;
        $this->frameValidator->handleStartOfFrame($marker, $payload, $offset);
    }

    /**
     * Finalises all assemblers and resolves deferred parse results.
     */
    private function finaliseParseResults(): void
    {
        $this->iccAssembler->finalise();
        $this->app1Handler->finalise();
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
    }

    /**
     * Creates the default APP marker-handler strategy registry.
     */
    private function createDefaultMarkerHandlerRegistry(): MarkerHandlerRegistry
    {
        return new MarkerHandlerRegistry([
            new ExifSegmentHandler($this->app1Handler->handleApp1(...)),
            new XmpSegmentHandler($this->app1Handler->handleApp1(...)),
            new IccProfileHandler($this->iccAssembler->handleSegment(...)),
            new AudioStreamHandler($this->audioParser->handleSegment(...)),
            new MpfDocumentHandler($this->handleMpfSegment(...)),
            new FlashPixHandler(function (string $payload, int $offset): void {
                $this->flashPixAssembler->handleSegment($payload, $offset);
            }),
            new IptcSegmentHandler(function (string $payload, int $offset): void {
                $this->iptcPayloads[] = $payload;
            }),
        ]);
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
}
