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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;

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
     * @param string $path        Path to the image file being inspected.
     * @param bool   $withDigests When true the SHA-1 and MD5 digests are calculated as part of the
     *                            returned metadata aggregate.
     */
    public function read(string $path, bool $withDigests = false): Metadata
    {
        $mimeType  = $this->detectMimeType($path);
        $fileSize  = $this->detectFileSize($path);
        $extension = $this->detectExtension($path);

        [$sha1, $md5] = $withDigests ? $this->calculateDigests($path) : [null, null];

        $stream = Stream::fromPath($path);

        try {
            $type = FormatDetector::detect($stream);
        } catch (ParseError $exception) {
            throw new ParseError('Only JPEG containers are supported by the core reader.', 0, $exception);
        }

        if ($type !== ContainerType::JPEG) {
            throw new ParseError('Only JPEG containers are supported by the core reader.');
        }

        return $this->fromJpeg(
            $stream,
            $mimeType,
            $fileSize,
            $extension,
            $sha1,
            $md5,
        );
    }

    /**
     * Extracts metadata from a JPEG container.
     */
    private function fromJpeg(
        Stream $stream,
        ?string $mimeType,
        ?int $fileSize,
        ?string $extension,
        ?string $digestSha1,
        ?string $digestMd5,
    ): Metadata {
        $jpeg = new JpegExtractor($stream);

        $exifBlobs = $jpeg->extractExifBlobs();
        $exifDoc   = null;
        if ($exifBlobs !== []) {
            $exifDoc = (new TiffExifReader())->parseFromBlob($exifBlobs[0]);
        }

        return new Metadata(
            $exifBlobs,
            $exifDoc,
            $jpeg->getFrameSamplePrecision(),
            $jpeg->getFrameComponentSamplingFactors(),
            $jpeg->getFrameYCbCrSubSampling(),
            $jpeg->getFrameWidth(),
            $jpeg->getFrameHeight(),
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
}
