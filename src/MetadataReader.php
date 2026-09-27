<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use Closure;
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
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\Model\Dji\DjiTelemetry;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\MetadataBuilder;
use MagicSunday\ImageMeta\Model\ParseWarning;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\DjiMdatTelemetryScanner;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactory;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactory;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParser;
use MagicSunday\ImageMeta\Parse\Riff\RiffParserFactory;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\Enum\ParseWarningScope;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use ValueError;

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
     * FormatDetector code for input that matches no supported container signature.
     */
    private const int UNSUPPORTED_CONTAINER_CODE = 1033;

    /** @var Closure(Metadata):StructuredMetadata */
    private Closure $structuredResolver;

    /**
     * @param TiffExifParserInterface $tiffReader           TIFF/EXIF parser instance.
     * @param AppleMakerNotesMerger   $appleMerger          Apple maker notes merger.
     * @param XmpParserInterface      $xmpParser            XMP parser instance.
     * @param IptcParserInterface     $iptcParser           IPTC parser instance.
     * @param FormatDetector          $formatDetector       Container format detector.
     * @param JpegParserFactory       $jpegParserFactory    Factory creating JPEG parser instances.
     * @param IsoBmffParserFactory    $isoBmffParserFactory Factory creating ISO BMFF parser instances.
     * @param RiffParserFactory       $riffParserFactory    Factory creating RIFF parser instances.
     * @param int                     $maxTiffSize          Maximum stream size in bytes before TIFF materialisation is rejected.
     */
    public function __construct(
        private TiffExifParserInterface $tiffReader,
        private AppleMakerNotesMerger $appleMerger,
        private XmpParserInterface $xmpParser,
        private IptcParserInterface $iptcParser,
        private FormatDetector $formatDetector,
        private JpegParserFactory $jpegParserFactory,
        private IsoBmffParserFactory $isoBmffParserFactory,
        private RiffParserFactory $riffParserFactory = new RiffParserFactory(),
        private int $maxTiffSize = self::MAX_TIFF_SIZE,
    ) {
        $builder                  = StructuredMetadataBuilder::createDefault();
        $this->structuredResolver = static fn (Metadata $metadata): StructuredMetadata => $builder->assemble($metadata);
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
            new RiffParserFactory(),
        );
    }

    /**
     * Reads metadata from the given file path by delegating to the appropriate parser.
     * When digests are requested, metadata parsing and digest computation use the same opened stream snapshot.
     *
     * Damaged content does not fail the read: everything that could be extracted is returned and
     * each tolerated failure is listed in Metadata::$warnings. Exceptions remain for input the
     * reader cannot treat as a damaged media file at all: a missing or unreadable path, a
     * directory, a rejected stream wrapper, or a container type it does not support (code 1033).
     *
     * @param string $path       Path to the image or media file being inspected.
     * @param bool   $withDigest When true the SHA-256 digest is calculated as part of the
     *                           returned metadata aggregate.
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function read(string $path, bool $withDigest = false): Metadata
    {
        if (is_dir($path)) {
            throw new ParseError(sprintf('Path is a directory, not a file: %s', $path), 1120);
        }

        $stream    = Stream::fromPath($path);
        $mimeType  = $this->detectMimeType($stream);
        $fileSize  = $stream->size();
        $extension = $this->detectExtension($path);

        $sha256 = $withDigest ? $this->calculateDigest($stream) : null;

        try {
            $type = $this->formatDetector->detect($stream);
        } catch (ParseError $exception) {
            if ($exception->getCode() === self::UNSUPPORTED_CONTAINER_CODE) {
                throw $exception;
            }

            // Postel's Law: a recognised but damaged or truncated signature
            // yields the file identity plus a warning instead of aborting.
            return $this->identityOnly($mimeType, $fileSize, $extension, $sha256, $exception);
        }

        try {
            return match ($type) {
                ContainerType::JPEG    => $this->fromJpeg($stream, $mimeType, $fileSize, $extension, $sha256),
                ContainerType::ISOBMFF => $this->fromIsoBmff($stream, $mimeType, $fileSize, $extension, $sha256),
                ContainerType::TIFF    => $this->fromTiff($stream, $mimeType, $fileSize, $extension, $sha256),
                ContainerType::JXL     => $this->fromJxl($stream, $mimeType, $fileSize, $extension, $sha256),
                ContainerType::RIFF    => $this->fromRiff($stream, $mimeType, $fileSize, $extension, $sha256),
            };
        } catch (BoundsError|ParseError $exception) {
            // Damage no container-level tolerance could localise (for example a
            // standalone TIFF whose directories cannot be read): keep the file
            // identity and report the failure instead of aborting.
            return $this->identityOnly($mimeType, $fileSize, $extension, $sha256, $exception);
        }
    }

    /**
     * Builds metadata that carries only the file identity plus the warning that stopped the read.
     */
    private function identityOnly(
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
        BoundsError|ParseError $exception,
    ): Metadata {
        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings([ParseWarning::fromThrowable(ParseWarningScope::Container, $exception)])
            ->build();
    }

    /**
     * Extracts metadata from a JPEG container.
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     *
     * @throws ParseError  If the input is malformed or inconsistent.
     * @throws BoundsError If a read reaches outside the declared byte range.
     */
    private function fromJpeg(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        $jpeg = $this->jpegParserFactory->create($stream, tolerateDamage: true);
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
        $warnings        = $jpeg->getWarnings();

        // Parse the primary EXIF blob and map vendor-specific maker notes.
        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($exifBlobs, $warnings, jpegContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs, $warnings);

        $iptcDoc = $this->parseIptcBlobs($iptcBlobs, $warnings);

        // Assemble the final metadata aggregate with container context.
        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withJpegSegments($iccProfile, $iccSegments, $flashPixStreams, $mpfDocument, $audioStreams, $jfifSegment)
            ->withJpegFrame($frameWidth, $frameHeight, $bitsPerSample, $sampling, $subSampling)
            ->withIptc($iptcBlobs, $iptcDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings($warnings)
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
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
     */
    private function fromIsoBmff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        $result   = $this->isoBmffParserFactory->create($stream, tolerateDamage: true)->extract();
        $warnings = $result->warnings;

        $qt = $result->quickTimeMeta;

        // Truncated DJI drone recordings lack a moov box. When the parser
        // found no EXIF/XMP payloads, scan the stream tail for DJI protobuf
        // telemetry and inject model/GPS data into QuickTime keys.
        if (($result->exifBlobs === []) && ($result->xmpBlobs === [])) {
            try {
                $qt = $this->enrichWithDjiTelemetry($stream, $qt);
            } catch (BoundsError|ParseError $exception) {
                $warnings[] = ParseWarning::fromThrowable(ParseWarningScope::Container, $exception);
            }
        }

        // ISO BMFF containers store image dimensions in the ispe box and
        // image data in mdat — TIFF-level dimension/strip/tile tags are
        // not required.  Unlike JPEG context, JPEG-prohibited tags
        // (ImageWidth etc.) may legitimately appear in the EXIF blob.
        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($result->exifBlobs, $warnings, embeddedContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, $qt);
        $xmpDoc     = $this->parseXmpBlobs($result->xmpBlobs, $warnings);

        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($result->exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($result->xmpBlobs, $xmpDoc)
            ->withQuickTime($qt)
            ->withIsoBmff($result->itemReferences, $result->dataReferences, $result->unresolvedItems, $result->ispeWidth, $result->ispeHeight, $result->iccProfile, $result->tmapItemIds)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings($warnings)
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
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
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
        $exifDoc = $this->tiffReader->parseFromStream($stream, $registry);

        $makerNotes = $this->appleMerger->merge($exifDoc->makerNotes(), null);

        $warnings = [];

        // Adobe XMP Part 3 — tag 700 (0x02BC) embeds XMP in TIFF IFD0
        $xmpBlobs = $exifDoc->xmpPacketRaw !== null ? [$exifDoc->xmpPacketRaw] : [];
        $xmpDoc   = $this->parseXmpBlobs($xmpBlobs, $warnings);

        // IPTC-IIM — tag 33723 (0x83BB) embeds IPTC/NAA in TIFF IFD0
        $iptcBlobs = $exifDoc->iptcNaaRaw !== null ? [$exifDoc->iptcNaaRaw] : [];
        $iptcDoc   = $this->parseIptcBlobs($iptcBlobs, $warnings);

        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withIccProfile($exifDoc->iccProfileRaw)
            ->withIptc($iptcBlobs, $iptcDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings($warnings)
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
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
     */
    private function fromJxl(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        $result   = (new JxlParser($stream, tolerateDamage: true))->extract();
        $warnings = $result->warnings;

        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($result->exifBlobs, $warnings, embeddedContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($result->xmpBlobs, $warnings);

        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($result->exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($result->xmpBlobs, $xmpDoc)
            ->withGainMapBlob($result->gainMapBlob)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings($warnings)
            ->build();
    }

    // jscpd:ignore-start

    /**
     * Extracts metadata from a RIFF/AVI container.
     *
     * @param Stream  $stream       Source stream positioned at the start of the file.
     * @param ?string $mimeType     MIME type associated with the inspected file.
     * @param ?int    $fileSize     File size in bytes if it could be determined.
     * @param ?string $extension    File extension detected from the path or stream.
     * @param ?string $digestSha256 Pre-computed SHA-256 digest for the stream contents.
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
     */
    private function fromRiff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha256,
    ): Metadata {
        $result   = $this->riffParserFactory->create($stream, tolerateDamage: true)->extract();
        $warnings = $result->warnings;

        [$exifDoc, $makerNotes] = $this->parseEmbeddedExifBlobs($result->exifBlobs, $warnings, embeddedContext: true);

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($result->xmpBlobs, $warnings);

        return (new MetadataBuilder($this->structuredResolver))
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($result->exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($result->xmpBlobs, $xmpDoc)
            ->withRiff($result->info, $result->aviHeader, $result->riffExif, $result->nikonCameraTags, $result->olympusCameraTags)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha256)
            ->withWarnings($warnings)
            ->build();
    }

    // jscpd:ignore-end

    /**
     * Scans the stream tail for DJI protobuf telemetry and injects model/GPS into QuickTime metadata.
     *
     * DJI drone video recordings embed per-frame telemetry as protobuf records in the
     * mdat stream. Truncated recordings (no moov box) lack conventional metadata, but
     * the telemetry stream still contains the drone model and GPS coordinates.
     *
     * @param Stream             $stream Source stream to scan.
     * @param QuickTimeMeta|null $qt     Existing QuickTime metadata from the parser.
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
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
     * An unreadable packet is skipped with an Xmp-scoped warning; the others are still merged.
     * When the readable packets contradict each other, the primary packet is kept.
     *
     * @param list<string>       $xmpBlobs Raw XMP packet strings.
     * @param list<ParseWarning> $warnings Collected warnings, extended in place.
     */
    private function parseXmpBlobs(array $xmpBlobs, array &$warnings): ?XmpDocument
    {
        $documents = [];

        foreach ($xmpBlobs as $blob) {
            try {
                $documents[] = $this->xmpParser->parse($blob);
            } catch (BoundsError|ParseError $exception) {
                $warnings[] = ParseWarning::fromThrowable(ParseWarningScope::Xmp, $exception);
            }
        }

        if ($documents === []) {
            return null;
        }

        try {
            return XmpDocument::merge(...$documents);
        } catch (ParseError $exception) {
            // Packets that contradict each other cannot be merged; keep the primary one.
            $warnings[] = ParseWarning::fromThrowable(ParseWarningScope::Xmp, $exception);

            return $documents[0];
        }
    }

    /**
     * Parses IPTC blobs and merges them into a single document.
     *
     * An unreadable payload is skipped with an Iptc-scoped warning; the others are still merged.
     *
     * @param list<string>       $iptcBlobs Raw IPTC/IIM record strings.
     * @param list<ParseWarning> $warnings  Collected warnings, extended in place.
     */
    private function parseIptcBlobs(array $iptcBlobs, array &$warnings): ?IptcDocument
    {
        $documents = [];

        foreach ($iptcBlobs as $blob) {
            try {
                $documents[] = $this->iptcParser->parse($blob);
            } catch (BoundsError|ParseError $exception) {
                $warnings[] = ParseWarning::fromThrowable(ParseWarningScope::Iptc, $exception);
            }
        }

        if ($documents === []) {
            return null;
        }

        return IptcDocument::merge(...$documents);
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

        if (!is_string($mime) || ($mime === '')) {
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
     *
     * @throws BoundsError If a read reaches outside the declared byte range.
     * @throws ParseError  If the input is malformed or inconsistent.
     */
    private function calculateDigest(Stream $stream): string
    {
        $context = hash_init('sha256');

        $stream->seek(0);

        $remaining = $stream->size();

        while ($remaining > 0) {
            $chunkLength = min($remaining, 1_048_576);
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
     * An unreadable primary blob yields no parsed document and an Exif-scoped warning; the raw
     * blobs stay available on the metadata aggregate.
     *
     * @param list<string>       $exifBlobs
     * @param list<ParseWarning> $warnings  Collected warnings, extended in place.
     *
     * @return array{0: ?ParsedExif, 1: ?MakerNotesRecord}
     */
    private function parseEmbeddedExifBlobs(
        array $exifBlobs,
        array &$warnings,
        bool $jpegContext = false,
        bool $embeddedContext = false,
    ): array {
        if ($exifBlobs === []) {
            return [null, null];
        }

        try {
            $exifDoc = $this->tiffReader->parseFromBlob(
                $exifBlobs[0],
                $this->createMakerNotesRegistry(),
                $jpegContext,
                $embeddedContext,
            );
        } catch (BoundsError|ParseError $exception) {
            $warnings[] = ParseWarning::fromThrowable(ParseWarningScope::Exif, $exception);

            return [null, null];
        }

        return [$exifDoc, $exifDoc->makerNotes()];
    }
}
