<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Api;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;

/**
 * Facade exposing EXIF-only access for supported container formats.
 */
final class ExifReader
{
    private readonly TiffExifReader $tiffReader;

    /**
     * Allows injecting a custom TIFF EXIF reader instance, e.g. for sharing caches in
     * higher-level code, while falling back to the default reader when none is provided.
     */
    public function __construct(?TiffExifReader $tiffReader = null)
    {
        $this->tiffReader = $tiffReader ?? new TiffExifReader();
    }

    /**
     * Reads EXIF metadata from JPEG and ISO-BMFF (e.g. HEIC, MOV, MP4) containers.
     *
     * @param string $path Absolute or relative path to the media file that should be parsed.
     *
     * @return ExifDocument Value object with parsed EXIF data and fallback image attributes.
     */
    public function read(string $path): ExifDocument
    {
        $stream = Stream::fromPath($path);
        $type   = FormatDetector::detect($stream);

        $exifBlobs             = [];
        $fallbackWidth         = null;
        $fallbackHeight        = null;
        $fallbackBitsPerSample = null;

        if ($type === ContainerType::JPEG) {
            $jpeg                  = new JpegExtractor($stream);
            $exifBlobs             = $jpeg->extractExifBlobs();
            // JPEG frames expose dimensions and bit depth, so we capture them as fallbacks
            // when EXIF data omits these values.
            $fallbackWidth         = $jpeg->getFrameWidth();
            $fallbackHeight        = $jpeg->getFrameHeight();
            $fallbackBitsPerSample = $jpeg->getFrameSamplePrecision();
        } else {
            $isoExtractor = new IsoBmffExtractor($stream);
            [$exifBlobs]  = $isoExtractor->extract();
            // ISO-BMFF containers can store multiple items with varying characteristics,
            // therefore we keep the fallback values null and rely on the parsed EXIF data.
        }

        $document = null;
        if ($exifBlobs !== []) {
            $document = $this->tiffReader->parseFromBlob($exifBlobs[0]);
        }

        return new ExifDocument($document, $fallbackWidth, $fallbackHeight, $fallbackBitsPerSample);
    }
}
