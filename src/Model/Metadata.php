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
     * @param list<string>       $xmpBlobs  XMP packets (RDF/XML), first is primary
     * @param ExifDocument|null  $exifDoc   Parsed representation of the primary EXIF document.
     * @param XmpDocument|null   $xmpDoc    Parsed representation of the primary XMP packet.
     */
    public function __construct(
        public readonly array $exifBlobs,
        public readonly ?QuickTimeMeta $quickTime,
        public readonly ?ExifDocument $exifDoc = null,
        public readonly array $xmpBlobs = [],
        public readonly ?XmpDocument $xmpDoc = null,
    ) {
    }
}
