<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use finfo;
use MagicSunday\ImageMeta\Contract\IptcParserInterface;
use MagicSunday\ImageMeta\Contract\TiffExifParserInterface;
use MagicSunday\ImageMeta\Contract\XmpParserInterface;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\Model\Dji\DjiTelemetry;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\MetadataBuilder;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\DjiMdatTelemetryScanner;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactory;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactory;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParserFactory;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use ValueError;

use function array_map;
use function class_exists;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_dir;
use function is_string;
use function pathinfo;
use function sprintf;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

/**
 * Coordinates format detection and metadata extraction for supported containers.
 */
final readonly class MetadataReader
{
    /**
     * Maximum number of bytes accepted when parsing standalone TIFF streams (256 MiB).
     */
    private const int MAX_TIFF_SIZE = 256 * 1024 * 1024;

    /**
     * @param TiffExifParserInterface       $tiffReader           TIFF/EXIF parser instance.
     * @param AppleMakerNotesMerger         $appleMerger          Apple maker notes merger.
     * @param XmpParserInterface            $xmpParser            XMP parser instance.
     * @param IptcParserInterface           $iptcParser           IPTC parser instance.
     * @param FormatDetector                $formatDetector       Container format detector.
     * @param JpegParserFactoryInterface    $jpegParserFactory    Factory creating JPEG parser instances.
     * @param IsoBmffParserFactoryInterface $isoBmffParserFactory Factory creating ISO BMFF parser instances.
     * @param JxlParserFactoryInterface     $jxlParserFactory     Factory creating JPEG XL parser instances.
     * @param int                           $maxTiffSize          Maximum stream size in bytes before TIFF materialisation is rejected.
     */
    public function __construct(
        private TiffExifParserInterface $tiffReader,
        private AppleMakerNotesMerger $appleMerger,
        private XmpParserInterface $xmpParser,
        private IptcParserInterface $iptcParser,
        private FormatDetector $formatDetector,
        private JpegParserFactoryInterface $jpegParserFactory,
        private IsoBmffParserFactoryInterface $isoBmffParserFactory,
        private JxlParserFactoryInterface $jxlParserFactory,
        private int $maxTiffSize = self::MAX_TIFF_SIZE,
    ) {
    }

    /**
     * Creates a metadata reader with default parser dependencies.
     */
    public static function createDefault(?TiffExifParserInterface $tiffReader = null): self
    {
        return new self(
            $tiffReader ?? new TiffExifParser(),
            new AppleMakerNotesMerger(),
            new XmpParser(),
            new IptcParser(),
            new FormatDetector(),
            new JpegParserFactory(),
            new IsoBmffParserFactory(),
            new JxlParserFactory(),
        );
    }

    /**
     * Reads metadata from the given file path by delegating to the appropriate parser.
     * When digests are requested, metadata parsing and digest computation use the same opened stream snapshot.
     *
     * @param string $path        Path to the image or media file being inspected.
     * @param bool   $withDigests When true the SHA-256 digest is calculated as part of the
     *                            returned metadata aggregate.
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function read(string $path, bool $withDigests = false): Metadata
    {
        if (is_dir($path)) {
            throw new ParseError(sprintf('Path is a directory, not a file: %s', $path), 1120);
        }

        $stream    = Stream::fromPath($path);
        $mimeType  = $this->detectMimeType($stream);
        $fileSize  = $stream->size();
        $extension = $this->detectExtension($path);

        $sha256 = $withDigests ? $this->calculateDigest($stream) : null;

        try {
            $type = $this->formatDetector->detect($stream);
        } catch (ParseError $exception) {
            if (($exception->getCode() === 1031) || ($exception->getCode() === 1032)) {
                // Postel's Law: tolerate unreadable/truncated signatures and
                // return empty metadata instead of aborting the whole read.
                return (new MetadataBuilder())
                    ->withParsers($this->xmpParser, $this->iptcParser)
                    ->withFileIdentity($mimeType, $fileSize, $extension, $sha256)
                    ->build();
            }

            throw $exception;
        }

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream, $mimeType, $fileSize, $extension, $sha256),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream, $mimeType, $fileSize, $extension, $sha256),
            ContainerType::TIFF    => $this->fromTiff($stream, $mimeType, $fileSize, $extension, $sha256),
            ContainerType::JXL     => $this->fromJxl($stream, $mimeType, $fileSize, $extension, $sha256),
        };
    }

    /**
     * Extracts metadata from a JPEG container.
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     */
    private function fromJpeg(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        $jpeg = $this->jpegParserFactory->create($stream);
        // Extract the JPEG segments along with frame and auxiliary stream data.
        $exifBlobs       = $jpeg->extractExifBlobs();
        $xmpBlobs        = $jpeg->extractXmpPackets();
        $iccProfile      = $jpeg->getIccProfile();
        $iccSegments     = $jpeg->getIccSegments();
        $flashPixStreams = $jpeg->getFlashPixStreams();
        $audioStreams    = $jpeg->getAudioStreams();
        $mpfDocument     = $jpeg->getMpfDocument();
        $iptcBlobs       = $jpeg->getIptcPayloads();
        $jfifSegment     = $jpeg->getJfifSegment();
        $bitsPerSample   = $jpeg->getFrameSamplePrecision();
        $frameHeight     = $jpeg->getFrameHeight();
        $frameWidth      = $jpeg->getFrameWidth();
        $sampling        = $jpeg->getFrameComponentSamplingFactors();
        $subSampling     = $jpeg->getFrameYCbCrSubSampling();

        // Parse the primary EXIF blob and map vendor-specific maker notes.
        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($exifBlobs, jpegContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        $iptcDoc = null;

        if ($iptcBlobs !== []) {
            $documents = array_map($this->iptcParser->parse(...), $iptcBlobs);

            $iptcDoc = IptcDocument::merge(...$documents);
        }

        // Assemble the final metadata aggregate with container context.
        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withJpegSegments($iccProfile, $iccSegments, $flashPixStreams, $mpfDocument, $audioStreams, $jfifSegment)
            ->withJpegFrame($frameWidth, $frameHeight, $bitsPerSample, $sampling, $subSampling)
            ->withIptc($iptcBlobs, $iptcDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->build();
    }

    /**
     * Extracts metadata from an ISO Base Media File Format container.
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     */
    private function fromIsoBmff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        [$exifBlobs, $xmpBlobs, $qt, $isoBmffItemReferences, $isoBmffDataReferences, $isoBmffUnresolvedItems, $ispeWidth, $ispeHeight, $iccProfile, $tmapItemIds] = $this->isoBmffParserFactory->create($stream)->extract();

        // Truncated DJI drone recordings lack a moov box. When the parser
        // found no EXIF/XMP payloads, scan the stream tail for DJI protobuf
        // telemetry and inject model/GPS data into QuickTime keys.
        if (($exifBlobs === []) && ($xmpBlobs === [])) {
            $qt = $this->enrichWithDjiTelemetry($stream, $qt);
        }

        // ISO BMFF containers store image dimensions in the ispe box and
        // image data in mdat — TIFF-level dimension/strip/tile tags are
        // not required.  Unlike JPEG context, JPEG-prohibited tags
        // (ImageWidth etc.) may legitimately appear in the EXIF blob.
        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($exifBlobs, embeddedContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, $qt);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withQuickTime($qt)
            ->withIsoBmff($isoBmffItemReferences, $isoBmffDataReferences, $isoBmffUnresolvedItems, $ispeWidth, $ispeHeight, $iccProfile, $tmapItemIds)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->build();
    }

    /**
     * Extracts metadata from a standalone TIFF-based container (TIFF, DNG, NEF, ARW).
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     */
    private function fromTiff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        if ($stream->size() > $this->maxTiffSize) {
            throw new ParseError(
                sprintf('TIFF stream size %d exceeds the maximum allowed size of %d bytes', $stream->size(), $this->maxTiffSize),
                1968,
            );
        }

        $registry  = $this->createMakerNotesRegistry();
        $exifBlobs = [];

        $stream->seek(0);

        if ($this->tiffReader instanceof TiffExifParser) {
            $exifDoc = $this->tiffReader->parseFromStream($stream, $registry);
        } else {
            // Compatibility fallback for custom parsers implementing only the blob contract.
            $tiffBlob  = $stream->read($stream->size());
            $exifDoc   = $this->tiffReader->parseFromBlob($tiffBlob, $registry);
            $exifBlobs = [$tiffBlob];
        }

        $makerNotes = $this->appleMerger->merge($exifDoc->makerNotes(), null);

        // Adobe XMP Part 3 — tag 700 (0x02BC) embeds XMP in TIFF IFD0
        $xmpBlobs = $exifDoc->xmpPacketRaw !== null ? [$exifDoc->xmpPacketRaw] : [];
        $xmpDoc   = $this->parseXmpBlobs($xmpBlobs);

        // IPTC-IIM — tag 33723 (0x83BB) embeds IPTC/NAA in TIFF IFD0
        $iptcBlobs = $exifDoc->iptcNaaRaw !== null ? [$exifDoc->iptcNaaRaw] : [];
        $iptcDoc   = null;

        if ($iptcBlobs !== []) {
            $documents = array_map($this->iptcParser->parse(...), $iptcBlobs);
            $iptcDoc   = IptcDocument::merge(...$documents);
        }

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withIccProfile($exifDoc->iccProfileRaw)
            ->withIptc($iptcBlobs, $iptcDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->build();
    }

    /**
     * Extracts metadata from a JPEG XL container.
     *
     * ISO/IEC 18181-2 defines JXL containers as ISO BMFF-compatible with top-level
     * `Exif` and `xml ` boxes for EXIF and XMP metadata respectively.
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     */
    private function fromJxl(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        [$exifBlobs, $xmpBlobs, $hrgmBlob] = $this->jxlParserFactory->create($stream)->extract();

        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($exifBlobs, embeddedContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withGainMapBlob($hrgmBlob)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->build();
    }

    /**
     * Scans the stream tail for DJI protobuf telemetry and injects model/GPS into QuickTime metadata.
     *
     * DJI drone video recordings embed per-frame telemetry as protobuf records in the
     * mdat stream. Truncated recordings (no moov box) lack conventional metadata, but
     * the telemetry stream still contains the drone model and GPS coordinates.
     *
     * @param Stream             $stream Source stream to scan.
     * @param QuickTimeMeta|null $qt     Existing QuickTime metadata from the parser.
     */
    private function enrichWithDjiTelemetry(Stream $stream, ?QuickTimeMeta $qt): ?QuickTimeMeta
    {
        $telemetry = (new DjiMdatTelemetryScanner())->scanStream($stream);

        if (!$telemetry instanceof DjiTelemetry) {
            return $qt;
        }

        $keys      = $qt instanceof QuickTimeMeta ? $qt->keys : [];
        $dataAtoms = $qt instanceof QuickTimeMeta ? $qt->dataAtoms : [];

        if ($telemetry->model !== null) {
            $keys['com.apple.quicktime.make']  = 'DJI';
            $keys['com.apple.quicktime.model'] = $telemetry->model;
        }

        if ($telemetry->latitude !== null) {
            $keys['com.apple.quicktime.location.latitude'] = $telemetry->latitude;
        }

        if ($telemetry->longitude !== null) {
            $keys['com.apple.quicktime.location.longitude'] = $telemetry->longitude;
        }

        if ($telemetry->altitude !== null) {
            $keys['com.apple.quicktime.location.altitude'] = $telemetry->altitude;
        }

        return new QuickTimeMeta($keys, $dataAtoms);
    }

    /**
     * Parses XMP blobs and merges them into a single document.
     *
     * @param list<string> $xmpBlobs Raw XMP packet strings.
     */
    private function parseXmpBlobs(array $xmpBlobs): ?XmpDocument
    {
        if ($xmpBlobs === []) {
            return null;
        }

        $documents = array_map($this->xmpParser->parse(...), $xmpBlobs);

        return XmpDocument::merge(...$documents);
    }

    /**
     * Attempts to detect the mime type from the opened stream using the file information extension.
     */
    private function detectMimeType(Stream $stream): ?string
    {
        if (!class_exists(finfo::class)) {
            return null;
        }

        $probeLength = min($stream->size(), 8192);

        $probe = '';

        try {
            $stream->seek(0);
            $probe = $probeLength === 0 ? '' : $stream->read($probeLength);
        } catch (BoundsError|ParseError) {
            return null;
        } finally {
            try {
                $stream->seek(0);
            } catch (BoundsError|ParseError) {
                // Ignore seek-reset failures in optional MIME detection fallback.
            }
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        try {
            $mime = $finfo->buffer($probe);
        } catch (ValueError) {
            return null;
        }

        if (!is_string($mime) || $mime === '') {
            return null;
        }

        return $mime;
    }

    /**
     * Extracts the file extension from the provided path.
     */
    private function detectExtension(string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return null;
        }

        return strtolower($extension);
    }

    /**
     * Calculates a SHA-256 digest by reading the opened stream once.
     */
    private function calculateDigest(Stream $stream): string
    {
        $context = hash_init('sha256');

        $stream->seek(0);

        $remaining = $stream->size();

        while ($remaining > 0) {
            $chunkLength = min($remaining, 8192);
            $chunk       = $stream->read($chunkLength);
            hash_update($context, $chunk);
            $remaining -= $chunkLength;
        }

        $stream->seek(0);

        return hash_final($context);
    }

    /**
     * Builds the maker notes registry populated with the bundled decoders.
     */
    private function createMakerNotesRegistry(): Registry
    {
        return RegistryFactory::createDefault();
    }

    /**
     * Parses the primary EXIF blob and returns the parsed document plus maker notes.
     *
     * @param list<string> $exifBlobs
     *
     * @return array{0: ?ParsedExif, 1: ?MakerNotesRecord}
     */
    private function parseEmbeddedExifBlobs(
        array $exifBlobs,
        bool $jpegContext = false,
        bool $embeddedContext = false,
    ): array {
        if ($exifBlobs === []) {
            return [null, null];
        }

        $registry = $this->createMakerNotesRegistry();
        $exifDoc  = $this->tiffReader->parseFromBlob(
            $exifBlobs[0],
            $registry,
            $jpegContext,
            $embeddedContext,
        );

        return [$exifDoc, $exifDoc->makerNotes()];
    }
}
