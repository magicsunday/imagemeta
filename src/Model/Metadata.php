<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Aggregates extracted metadata blobs alongside parsed representations.
 */
final readonly class Metadata
{
    /**
     * @param list<string>       $exifBlobs TIFF‑EXIF blobs (first is primary)
     * @param QuickTimeMeta|null $quickTime QuickTime metadata extracted from ISO BMFF containers.
     * @param ExifDocument|null  $exifDoc   Parsed representation of the primary EXIF document.
     * @param list<string>       $xmpBlobs  XMP packets (RDF/XML), first is primary
     * @param XmpDocument|null   $xmpDoc    Parsed representation of the primary XMP packet.
     */
    public function __construct(
        public array $exifBlobs,
        public ?QuickTimeMeta $quickTime,
        public ?ExifDocument $exifDoc = null,
        public array $xmpBlobs = [],
        public ?XmpDocument $xmpDoc = null,
    ) {
    }
}
