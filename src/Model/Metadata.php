<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

final class Metadata
{
    /**
     * @param list<string> $exifBlobs   TIFF‑EXIF blobs (first is primary)
     * @param list<string> $xmpBlobs    XMP packets (RDF/XML), first is primary
     */
    public function __construct(
        public readonly array $exifBlobs,
        public readonly ?QuickTimeMeta $quickTime,
        public readonly ?ExifDocument $exifDoc = null,
        public readonly array $xmpBlobs = [],
        public readonly ?XmpDocument $xmpDoc = null
    ) {}
}
