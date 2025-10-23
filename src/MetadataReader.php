<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Parse\Xmp\XmpReader;

/**
 * Coordinates format detection and metadata extraction for supported containers.
 */
final class MetadataReader
{
    /**
     * Reads metadata from the given file path by delegating to the appropriate parser.
     *
     * @param string $path Path to the image or media file being inspected.
     *
     * @return Metadata
     */
    public function read(string $path): Metadata
    {
        $stream = Stream::fromPath($path);
        $type   = FormatDetector::detect($stream);

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream),
        };
    }

    /**
     * Extracts metadata from a JPEG container.
     *
     * @param Stream $stream Source stream positioned at the start of the file.
     *
     * @return Metadata
     */
    private function fromJpeg(Stream $stream): Metadata
    {
        $jpeg      = new JpegExtractor($stream);
        $exifBlobs = $jpeg->extractExifBlobs();
        $xmpBlobs  = $jpeg->extractXmpPackets();

        $exifDoc = null;
        $xmpDoc  = null;
        if ($exifBlobs !== []) {
            $exifDoc = (new TiffExifReader())->parseFromBlob($exifBlobs[0]);
        }

        if ($xmpBlobs !== []) {
            $xmpDoc = (new XmpReader())->parse($xmpBlobs[0]);
        }

        return new Metadata($exifBlobs, null, $exifDoc, $xmpBlobs, $xmpDoc);
    }

    /**
     * Extracts metadata from an ISO Base Media File Format container.
     *
     * @param Stream $stream Source stream positioned at the start of the file.
     *
     * @return Metadata
     */
    private function fromIsoBmff(Stream $stream): Metadata
    {
        [$exifBlobs, $xmpBlobs, $qt] = (new IsoBmffExtractor($stream))->extract();

        $exifDoc = null;
        $xmpDoc  = null;
        if ($exifBlobs !== []) {
            $exifDoc = (new TiffExifReader())->parseFromBlob($exifBlobs[0]);
        }

        if ($xmpBlobs !== []) {
            $xmpDoc = (new XmpReader())->parse($xmpBlobs[0]);
        }

        return new Metadata($exifBlobs, $qt, $exifDoc, $xmpBlobs, $xmpDoc);
    }
}
