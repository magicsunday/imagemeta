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
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\MetadataBuilder;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactory;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactory;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParserFactory;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;

use function class_exists;
use function filesize;
use function hash_file;
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
     * Maximum number of bytes accepted when materialising a TIFF stream into memory (256 MiB).
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
     *
     * @param string $path        Path to the image or media file being inspected.
     * @param bool   $withDigests When true the SHA-1 and MD5 digests are calculated as part of the
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

        $mimeType  = $this->detectMimeType($path);
        $fileSize  = $this->detectFileSize($path);
        $extension = $this->detectExtension($path);

        [$sha1, $md5] = $withDigests ? $this->calculateDigests($path) : [null, null];

        $stream = Stream::fromPath($path);
        $type   = $this->formatDetector->detect($stream);

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
            ContainerType::TIFF    => $this->fromTiff($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
            ContainerType::JXL     => $this->fromJxl($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
        };
    }

    /**
     * Extracts metadata from a JPEG container.
     *
     * @param Stream  $stream     Source stream positioned at the start of the file.
     * @param ?string $mimeType   MIME type associated with the inspected file.
     * @param ?int    $fileSize   File size in bytes if it could be determined.
     * @param ?string $extension  File extension detected from the path or stream.
     * @param ?string $digestSha1 Pre-computed SHA-1 digest for the stream contents.
     * @param ?string $digestMd5  Pre-computed MD5 digest for the stream contents.
     */
    private function fromJpeg(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
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
        $bitsPerSample   = $jpeg->getFrameSamplePrecision();
        $frameHeight     = $jpeg->getFrameHeight();
        $frameWidth      = $jpeg->getFrameWidth();
        $sampling        = $jpeg->getFrameComponentSamplingFactors();
        $subSampling     = $jpeg->getFrameYCbCrSubSampling();

        $exifDoc    = null;
        $makerNotes = null;
        // Parse the primary EXIF blob and map vendor-specific maker notes.
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = $this->tiffReader->parseFromBlob($exifBlobs[0], $registry, jpegContext: true);
            $makerNotes = $exifDoc->makerNotes();
        }

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        $iptcDoc = null;
        if ($iptcBlobs !== []) {
            $documents = [];

            foreach ($iptcBlobs as $blob) {
                $documents[] = $this->iptcParser->parse($blob);
            }

            $iptcDoc = IptcDocument::merge(...$documents);
        }

        // Assemble the final metadata aggregate with container context.
        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withJpegSegments($iccProfile, $iccSegments, $flashPixStreams, $mpfDocument, $audioStreams)
            ->withJpegFrame($frameWidth, $frameHeight, $bitsPerSample, $sampling, $subSampling)
            ->withIptc($iptcBlobs, $iptcDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha1, $digestMd5)
            ->build();
    }

    /**
     * Extracts metadata from an ISO Base Media File Format container.
     *
     * @param Stream  $stream     Source stream positioned at the start of the file.
     * @param ?string $mimeType   MIME type associated with the inspected file.
     * @param ?int    $fileSize   File size in bytes if it could be determined.
     * @param ?string $extension  File extension detected from the path or stream.
     * @param ?string $digestSha1 Pre-computed SHA-1 digest for the stream contents.
     * @param ?string $digestMd5  Pre-computed MD5 digest for the stream contents.
     */
    private function fromIsoBmff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        [$exifBlobs, $xmpBlobs, $qt, $isoBmffItemReferences, $isoBmffDataReferences, $isoBmffUnresolvedItems, $ispeWidth, $ispeHeight] = $this->isoBmffParserFactory->create($stream)->extract();

        $exifDoc    = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry = $this->createMakerNotesRegistry();

            // ISO BMFF containers store image dimensions in the ispe box and
            // image data in mdat — TIFF-level dimension/strip/tile tags are
            // not required.  Unlike JPEG context, JPEG-prohibited tags
            // (ImageWidth etc.) may legitimately appear in the EXIF blob.
            $exifDoc    = $this->tiffReader->parseFromBlob($exifBlobs[0], $registry, embeddedContext: true);
            $makerNotes = $exifDoc->makerNotes();
        }

        $makerNotes = $this->appleMerger->merge($makerNotes, $qt);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withQuickTime($qt)
            ->withIsoBmff($isoBmffItemReferences, $isoBmffDataReferences, $isoBmffUnresolvedItems, $ispeWidth, $ispeHeight)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha1, $digestMd5)
            ->build();
    }

    /**
     * Extracts metadata from a standalone TIFF-based container (TIFF, DNG, NEF, ARW).
     *
     * @param Stream  $stream     Source stream positioned at the start of the file.
     * @param ?string $mimeType   MIME type associated with the inspected file.
     * @param ?int    $fileSize   File size in bytes if it could be determined.
     * @param ?string $extension  File extension detected from the path or stream.
     * @param ?string $digestSha1 Pre-computed SHA-1 digest for the stream contents.
     * @param ?string $digestMd5  Pre-computed MD5 digest for the stream contents.
     */
    private function fromTiff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        if ($stream->size() > $this->maxTiffSize) {
            throw new ParseError(
                sprintf('TIFF stream size %d exceeds the maximum allowed size of %d bytes', $stream->size(), $this->maxTiffSize),
                1122,
            );
        }

        $stream->seek(0);
        $tiffBlob = $stream->read($stream->size());

        $registry   = $this->createMakerNotesRegistry();
        $exifDoc    = $this->tiffReader->parseFromBlob($tiffBlob, $registry);
        $makerNotes = $exifDoc->makerNotes();
        $makerNotes = $this->appleMerger->merge($makerNotes, null);

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif([$tiffBlob], $exifDoc, $makerNotes)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha1, $digestMd5)
            ->build();
    }

    /**
     * Extracts metadata from a JPEG XL container.
     *
     * ISO/IEC 18181-2 defines JXL containers as ISO BMFF-compatible with top-level
     * `Exif` and `xml ` boxes for EXIF and XMP metadata respectively.
     *
     * @param Stream  $stream     Source stream positioned at the start of the file.
     * @param ?string $mimeType   MIME type associated with the inspected file.
     * @param ?int    $fileSize   File size in bytes if it could be determined.
     * @param ?string $extension  File extension detected from the path or stream.
     * @param ?string $digestSha1 Pre-computed SHA-1 digest for the stream contents.
     * @param ?string $digestMd5  Pre-computed MD5 digest for the stream contents.
     */
    private function fromJxl(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        [$exifBlobs, $xmpBlobs] = $this->jxlParserFactory->create($stream)->extract();

        $exifDoc    = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = $this->tiffReader->parseFromBlob($exifBlobs[0], $registry, embeddedContext: true);
            $makerNotes = $exifDoc->makerNotes();
        }

        $makerNotes = $this->appleMerger->merge($makerNotes, null);
        $xmpDoc     = $this->parseXmpBlobs($xmpBlobs);

        return (new MetadataBuilder())
            ->withParsers($this->xmpParser, $this->iptcParser)
            ->withExif($exifBlobs, $exifDoc, $makerNotes)
            ->withXmp($xmpBlobs, $xmpDoc)
            ->withFileIdentity($mimeType, $fileSize, $extension, $digestSha1, $digestMd5)
            ->build();
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

        $documents = [];

        foreach ($xmpBlobs as $blob) {
            $documents[] = $this->xmpParser->parse($blob);
        }

        return XmpDocument::merge(...$documents);
    }

    /**
     * Attempts to detect the mime type of the provided path using the file information extension.
     */
    private function detectMimeType(string $path): ?string
    {
        if (!class_exists(finfo::class)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = @$finfo->file($path);

        if (!is_string($mime) || $mime === '') {
            return null;
        }

        return $mime;
    }

    /**
     * Returns the filesize in bytes or null when not available.
     */
    private function detectFileSize(string $path): ?int
    {
        $size = @filesize($path);

        if ($size === false) {
            return null;
        }

        return $size;
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
     * Calculates the SHA-1 and MD5 digests for the provided path.
     *
     * @return array{0:?string,1:?string}
     */
    private function calculateDigests(string $path): array
    {
        $sha1 = hash_file('sha1', $path);
        $md5  = hash_file('md5', $path);

        return [
            is_string($sha1) ? $sha1 : null,
            is_string($md5) ? $md5 : null,
        ];
    }

    /**
     * Builds the maker notes registry populated with the bundled decoders.
     */
    private function createMakerNotesRegistry(): Registry
    {
        return RegistryFactory::createDefault();
    }
}
