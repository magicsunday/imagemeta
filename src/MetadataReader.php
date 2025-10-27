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
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMapper;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;

use function class_exists;
use function filesize;
use function hash_file;
use function is_string;
use function pathinfo;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

/**
 * Coordinates format detection and metadata extraction for supported containers.
 */
final class MetadataReader
{
    /**
     * Reads metadata from the given file path by delegating to the appropriate parser.
     *
     * @param string $path        Path to the image or media file being inspected.
     * @param bool   $withDigests When true the SHA-1 and MD5 digests are calculated as part of the
     *                            returned metadata aggregate.
     *
     * @return Metadata
     */
    public function read(string $path, bool $withDigests = false): Metadata
    {
        $mimeType  = $this->detectMimeType($path);
        $fileSize  = $this->detectFileSize($path);
        $extension = $this->detectExtension($path);

        [$sha1, $md5] = $withDigests ? $this->calculateDigests($path) : [null, null];

        $stream = Stream::fromPath($path);
        $type   = FormatDetector::detect($stream);

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream, $mimeType, $fileSize, $extension, $sha1, $md5),
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
     *
     * @return Metadata
     */
    private function fromJpeg(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        $jpeg             = new JpegExtractor($stream);
        $exifBlobs        = $jpeg->extractExifBlobs();
        $xmpBlobs         = $jpeg->extractXmpPackets();
        $iccProfile       = $jpeg->getIccProfile();
        $iccSegments      = $jpeg->getIccSegments();
        $flashPixStreams  = $jpeg->getFlashPixStreams();
        $audioStreams     = $jpeg->getAudioStreams();
        $mpfDocument      = $jpeg->getMpfDocument();
        $bitsPerSample    = $jpeg->getFrameSamplePrecision();
        $frameHeight      = $jpeg->getFrameHeight();
        $frameWidth       = $jpeg->getFrameWidth();
        $sampling         = $jpeg->getFrameComponentSamplingFactors();
        $subSampling      = $jpeg->getFrameYCbCrSubSampling();

        $appleMapper = new AppleMakerNotesMapper();

        $exifDoc    = null;
        $xmpDoc     = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = (new TiffExifReader())->parseFromBlob($exifBlobs[0], $registry);
            $makerNotes = $exifDoc->makerNotes();
        }

        $makerNotes = $appleMapper->map($makerNotes, null);

        if ($xmpBlobs !== []) {
            $xmpDoc = (new XmpParser())->parse($xmpBlobs[0]);
        }

        return new Metadata(
            $exifBlobs,
            null,
            $exifDoc,
            $xmpBlobs,
            $xmpDoc,
            $makerNotes,
            $iccProfile,
            $iccSegments,
            $flashPixStreams,
            $mpfDocument,
            $audioStreams,
            $bitsPerSample,
            $sampling,
            $subSampling,
            mimeType: $mimeType,
            fileSize: $fileSize,
            extension: $extension,
            digestSha1: $digestSha1,
            digestMd5: $digestMd5,
            jpegFrameWidth: $frameWidth,
            jpegFrameHeight: $frameHeight,
        );
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
     *
     * @return Metadata
     */
    private function fromIsoBmff(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        [$exifBlobs, $xmpBlobs, $qt] = (new IsoBmffExtractor($stream))->extract();

        $appleMapper = new AppleMakerNotesMapper();

        $exifDoc    = null;
        $xmpDoc     = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = (new TiffExifReader())->parseFromBlob($exifBlobs[0], $registry);
            $makerNotes = $exifDoc->makerNotes();
        }

        $makerNotes = $appleMapper->map($makerNotes, $qt);

        if ($xmpBlobs !== []) {
            $xmpDoc = (new XmpParser())->parse($xmpBlobs[0]);
        }

        return new Metadata(
            $exifBlobs,
            $qt,
            $exifDoc,
            $xmpBlobs,
            $xmpDoc,
            $makerNotes,
            null,
            [],
            [],
            null,
            [],
            null,
            null,
            null,
            $mimeType,
            $fileSize,
            $extension,
            $digestSha1,
            $digestMd5,
        );
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
        $mime  = $finfo->file($path);

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
        $size = filesize($path);

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
