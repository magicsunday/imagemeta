<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Factory\StructuredMetadataCache;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\StructuredMetadata;

/**
 * Aggregates extracted metadata blobs alongside parsed representations.
 */
final readonly class Metadata
{
    /**
     * Lazily assembled structured metadata cache.
     */
    private StructuredMetadataCache $structuredCache;

    /**
     * @param list<string>                                         $exifBlobs                TIFF‑EXIF blobs (first is primary)
     * @param QuickTimeMeta|null                                   $quickTime                QuickTime metadata extracted from ISO BMFF containers.
     * @param ParsedExif|null                                      $exifDoc                  Parsed representation of the primary EXIF document.
     * @param list<string>                                         $xmpBlobs                 XMP packets (RDF/XML), first is primary
     * @param XmpDocument|null                                     $xmpDoc                   Parsed representation of the primary XMP packet.
     * @param MakerNotesRecord|null                                $makerNotes               Decoded maker notes metadata for the primary EXIF blob.
     * @param string|null                                          $iccProfile               Binary ICC profile when available.
     * @param list<string>                                         $iccSegments              Raw ICC APP2 segments in encounter order.
     * @param array<int, string>                                   $flashPixStreams          Concatenated FlashPix extension streams keyed by FPXR contents-list index.
     * @param MpfDocument|null                                     $mpfDocument              Parsed MPF document derived from APP2 segments.
     * @param list<JpegAudioStream>                                $jpegAudioStreams         EXIF audio streams embedded in JPEG APP2 markers.
     * @param int|null                                             $jpegBitsPerSample        Sample precision reported by the JPEG frame header.
     * @param array<int, array{horizontal:int, vertical:int}>|null $jpegFrameSamplingFactors Component sampling factors by identifier.
     * @param array{0:int,1:int}|null                              $jpegYCbCrSubSampling     Derived YCbCr subsampling from the JPEG frame header.
     * @param int|null                                             $jpegFrameWidth           Frame width reported by the JPEG start of frame marker.
     * @param int|null                                             $jpegFrameHeight          Frame height reported by the JPEG start of frame marker.
     * @param string|null                                          $mimeType                 Detected mime type for the source file.
     * @param int|null                                             $fileSize                 Size of the source file in bytes.
     * @param string|null                                          $extension                Lowercase file extension extracted from the path.
     * @param string|null                                          $digestSha1               Lowercase hexadecimal SHA-1 digest of the payload.
     * @param string|null                                          $digestMd5                Lowercase hexadecimal MD5 digest of the payload.
     * @param IsoBmffItemReferenceMap|null                         $isoBmffItemReferences    ISO BMFF item references extracted from metadata boxes.
     * @param IsoBmffDataReferenceMap|null                         $isoBmffDataReferences    ISO BMFF data references extracted from metadata boxes.
     * @param list<IsoBmffUnresolvedItem>                          $isoBmffUnresolvedItems   ISO BMFF item payloads that could not be resolved.
     * @param list<string>                                         $iptcBlobs                IPTC payloads captured from JPEG APP13 segments.
     * @param IptcDocument|null                                    $iptcDoc                  Parsed IPTC IIM datasets from APP13 payloads.
     */
    public function __construct(
        public array $exifBlobs,
        public ?QuickTimeMeta $quickTime,
        public ?ParsedExif $exifDoc = null,
        public array $xmpBlobs = [],
        public ?XmpDocument $xmpDoc = null,
        public ?MakerNotesRecord $makerNotes = null,
        public ?string $iccProfile = null,
        public array $iccSegments = [],
        public array $flashPixStreams = [],
        public ?MpfDocument $mpfDocument = null,
        public array $jpegAudioStreams = [],
        public ?int $jpegBitsPerSample = null,
        public ?array $jpegFrameSamplingFactors = null,
        public ?array $jpegYCbCrSubSampling = null,
        public ?string $mimeType = null,
        public ?int $fileSize = null,
        public ?string $extension = null,
        public ?string $digestSha1 = null,
        public ?string $digestMd5 = null,
        public ?int $jpegFrameWidth = null,
        public ?int $jpegFrameHeight = null,
        public ?IsoBmffItemReferenceMap $isoBmffItemReferences = null,
        public ?IsoBmffDataReferenceMap $isoBmffDataReferences = null,
        public array $isoBmffUnresolvedItems = [],
        public array $iptcBlobs = [],
        public ?IptcDocument $iptcDoc = null,
    ) {
        $this->structuredCache = StructuredMetadataCache::createDefault();
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

        $parser    = new XmpParser();
        $documents = [];

        foreach ($this->xmpBlobs as $blob) {
            $documents[] = $parser->parse($blob);
        }

        return XmpDocument::merge(...$documents);
    }

    /**
     * Returns the primary IPTC document, parsing IPTC payloads when needed.
     */
    public function selectiveIptcDocument(): ?IptcDocument
    {
        if ($this->iptcDoc instanceof IptcDocument) {
            return $this->iptcDoc;
        }

        if ($this->iptcBlobs === []) {
            return null;
        }

        $parser    = new IptcParser();
        $documents = [];

        foreach ($this->iptcBlobs as $blob) {
            $documents[] = $parser->parse($blob);
        }

        return IptcDocument::merge(...$documents);
    }

    /**
     * Returns curated structured metadata derived lazily from the available sources.
     */
    public function structured(): StructuredMetadata
    {
        return $this->structuredCache->resolve($this);
    }
}
