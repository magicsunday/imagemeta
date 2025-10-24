<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;
use function fclose;
use function feof;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function filesize;
use function fopen;
use function fread;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_string;
use function pathinfo;
use function strtolower;

/**
 * Coordinates format detection and metadata extraction for supported containers.
 */
final class MetadataReader
{
    /**
     * Reads metadata from the given file path by delegating to the appropriate parser.
     *
     * @param string $path              Path to the image or media file being inspected.
     * @param bool   $calculateDigests  When true SHA-1 and MD5 digests are streamed from disk.
     *
     * @return Metadata
     */
    public function read(string $path, bool $calculateDigests = false): Metadata
    {
        $mimeType = null;
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $path);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }

            finfo_close($finfo);
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            $fileSize = null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            $extension = null;
        } else {
            $extension = strtolower($extension);
        }

        $digestSha1 = null;
        $digestMd5  = null;
        if ($calculateDigests) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new ParseError('Cannot open for digest: ' . $path);
            }

            $sha1 = hash_init('sha1');
            $md5  = hash_init('md5');

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false) {
                        throw new ParseError('Digest read failure: ' . $path);
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    hash_update($sha1, $chunk);
                    hash_update($md5, $chunk);
                }
            } finally {
                fclose($handle);
            }

            $digestSha1 = hash_final($sha1);
            $digestMd5  = hash_final($md5);
        }

        $stream = Stream::fromPath($path);
        $type   = FormatDetector::detect($stream);

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream, $mimeType, $fileSize, $extension, $digestSha1, $digestMd5),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream, $mimeType, $fileSize, $extension, $digestSha1, $digestMd5),
        };
    }

    /**
     * Extracts metadata from a JPEG container.
     *
     * @param Stream $stream Source stream positioned at the start of the file.
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
    ): Metadata
    {
        $jpeg       = new JpegExtractor($stream);
        $exifBlobs  = $jpeg->extractExifBlobs();
        $xmpBlobs   = $jpeg->extractXmpPackets();
        $iccProfile = $jpeg->getIccProfile();
        $iccSegments = $jpeg->getIccSegments();

        $exifDoc    = null;
        $xmpDoc     = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = (new TiffExifReader())->parseFromBlob($exifBlobs[0], $registry);
            $makerNotes = $exifDoc->makerNotes();
        }

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
            $mimeType,
            $fileSize,
            $extension,
            $digestSha1,
            $digestMd5,
        );
    }

    /**
     * Extracts metadata from an ISO Base Media File Format container.
     *
     * @param Stream $stream Source stream positioned at the start of the file.
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
    ): Metadata
    {
        [$exifBlobs, $xmpBlobs, $qt] = (new IsoBmffExtractor($stream))->extract();

        $exifDoc    = null;
        $xmpDoc     = null;
        $makerNotes = null;
        if ($exifBlobs !== []) {
            $registry   = $this->createMakerNotesRegistry();
            $exifDoc    = (new TiffExifReader())->parseFromBlob($exifBlobs[0], $registry);
            $makerNotes = $exifDoc->makerNotes();
        }

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
            $mimeType,
            $fileSize,
            $extension,
            $digestSha1,
            $digestMd5,
        );
    }

    /**
     * Builds the maker notes registry populated with the bundled decoders.
     */
    private function createMakerNotesRegistry(): Registry
    {
        return RegistryFactory::createDefault();
    }
}
