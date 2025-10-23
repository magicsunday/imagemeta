<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;

/**
 * Aggregates extracted metadata blobs alongside parsed representations.
 */
final readonly class Metadata
{
    /**
     * @param list<string>          $exifBlobs TIFF‑EXIF blobs (first is primary)
     * @param QuickTimeMeta|null    $quickTime QuickTime metadata extracted from ISO BMFF containers.
     * @param ExifDocument|null     $exifDoc   Parsed representation of the primary EXIF document.
     * @param list<string>          $xmpBlobs  XMP packets (RDF/XML), first is primary
     * @param XmpDocument|null      $xmpDoc    Parsed representation of the primary XMP packet.
     * @param MakerNotesMetadata|null $makerNotes Decoded maker notes metadata for the primary EXIF blob.
     */
    public function __construct(
        public array $exifBlobs,
        public ?QuickTimeMeta $quickTime,
        public ?ExifDocument $exifDoc = null,
        public array $xmpBlobs = [],
        public ?XmpDocument $xmpDoc = null,
        public ?MakerNotesMetadata $makerNotes = null,
    ) {
    }

    /**
     * Returns the primary XMP document, optionally parsing it via the lightweight parser when
     * no pre-parsed document has been supplied.
     *
     * The method keeps existing behaviour for callers that already provided an \MagicSunday\ImageMeta\Model\Xmp\XmpDocument
     * instance while allowing consumers of the aggregate to obtain a curated subset of XMP data without having
     * to instantiate the parser manually.
     */
    public function selectiveXmpDocument(): ?XmpDocument
    {
        if ($this->xmpDoc instanceof XmpDocument) {
            return $this->xmpDoc;
        }

        if ($this->xmpBlobs === []) {
            return null;
        }

        return (new XmpParser())->parse($this->xmpBlobs[0]);
    }
}
