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
     * @param TiffExifReader|null $tiffReader Optional TIFF reader used to parse extracted EXIF blobs.
     *                                        When omitted a default reader instance is created.
     */
    public function __construct(?TiffExifReader $tiffReader = null)
    {
        $this->tiffReader = $tiffReader ?? new TiffExifReader();
    }

    /**
     * @param string $path Absolute or relative file system path that should be inspected for EXIF data.
     *
     * @return ExifDocument Structured EXIF wrapper that exposes curated accessors. When JPEG frame
     *                      dimensions or precision are available they are used as fallbacks when the
     *                      parsed EXIF payload omits corresponding image width, height, or bit depth
     *                      values.
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
            $fallbackWidth         = $jpeg->getFrameWidth();
            $fallbackHeight        = $jpeg->getFrameHeight();
            $fallbackBitsPerSample = $jpeg->getFrameSamplePrecision();
        } else {
            $isoExtractor = new IsoBmffExtractor($stream);
            [$exifBlobs]  = $isoExtractor->extract();
        }

        $document = null;
        if ($exifBlobs !== []) {
            $document = $this->tiffReader->parseFromBlob($exifBlobs[0]);
        }

        return new ExifDocument($document, $fallbackWidth, $fallbackHeight, $fallbackBitsPerSample);
    }
}
