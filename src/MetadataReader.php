<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Parse\Xmp\XmpReader;

final class MetadataReader
{
    public function read(string $path): Metadata
    {
        $stream = Stream::fromPath($path);
        $type = FormatDetector::detect($stream);

        return match ($type) {
            ContainerType::JPEG    => $this->fromJpeg($stream),
            ContainerType::ISOBMFF => $this->fromIsoBmff($stream),
        };
    }

    private function fromJpeg(Stream $stream): Metadata
    {
        $jpeg = new JpegExtractor($stream);
        $exifBlobs = $jpeg->extractExifBlobs();
        $xmpBlobs  = $jpeg->extractXmpPackets();

        $exifDoc = null; $xmpDoc = null;
        if ($exifBlobs !== []) { $exifDoc = (new TiffExifReader())->parseFromBlob($exifBlobs[0]); }
        if ($xmpBlobs !== [])  { $xmpDoc  = (new XmpReader())->parse($xmpBlobs[0]); }

        return new Metadata($exifBlobs, null, $exifDoc, $xmpBlobs, $xmpDoc);
    }

    private function fromIsoBmff(Stream $stream): Metadata
    {
        [$exifBlobs, $xmpBlobs, $qt] = (new IsoBmffExtractor($stream))->extract();

        $exifDoc = null; $xmpDoc = null;
        if ($exifBlobs !== []) { $exifDoc = (new TiffExifReader())->parseFromBlob($exifBlobs[0]); }
        if ($xmpBlobs !== [])  { $xmpDoc  = (new XmpReader())->parse($xmpBlobs[0]); }

        return new Metadata($exifBlobs, $qt, $exifDoc, $xmpBlobs, $xmpDoc);
    }
}
