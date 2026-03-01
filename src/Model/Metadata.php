<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Contract\IptcParserInterface;
use MagicSunday\ImageMeta\Contract\XmpParserInterface;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
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
use MagicSunday\ImageMeta\Value\StructuredMetadata;

/**
 * Aggregates extracted metadata blobs alongside parsed representations.
 *
 * Not every property is populated for every container type.  The table below
 * shows which groups of properties are available per container format detected
 * by {@see \MagicSunday\ImageMeta\MetadataReader::read()}.
 *
 * | Property group                     | JPEG | ISO BMFF | TIFF | JXL  |
 * |------------------------------------|:----:|:--------:|:----:|:----:|
 * | File identity (mimeType, fileSize, |      |          |      |      |
 * |   extension, digestSha1, digestMd5)|  Y   |    Y     |  Y   |  Y   |
 * | EXIF (exifBlobs, exifDoc,          |      |          |      |      |
 * |   makerNotes)                      |  Y   |    Y     |  Y   |  Y   |
 * | XMP (xmpBlobs, xmpDoc)             |  Y   |    Y     |  --  |  Y   |
 * | QuickTime (quickTime)              |  --  |    Y     |  --  |  --  |
 * | ICC profile (iccProfile)            |  Y   |    Y     |  --  |  --  |
 * | JPEG segments (iccSegments,        |      |          |      |      |
 * |   flashPixStreams, mpfDocument,     |      |          |      |      |
 * |   jpegAudioStreams)                 |  Y   |    --    |  --  |  --  |
 * | JPEG frame (jpegBitsPerSample,     |      |          |      |      |
 * |   jpegFrameSamplingFactors,         |      |          |      |      |
 * |   jpegYCbCrSubSampling,            |      |          |      |      |
 * |   jpegFrameWidth, jpegFrameHeight)  |  Y   |    --    |  --  |  --  |
 * | ISO BMFF (isoBmffItemReferences,   |      |          |      |      |
 * |   isoBmffDataReferences,            |      |          |      |      |
 * |   isoBmffUnresolvedItems)           |  --  |    Y     |  --  |  --  |
 * | HDR gain map (gainMapBlob)          |  --  |    --    |  --  |  Y   |
 * | IPTC (iptcBlobs, iptcDoc)           |  Y   |    --    |  --  |  --  |
 *
 * Properties outside their supported container group remain at their default
 * value (null for scalars/objects, empty array for list types).
 */
final readonly class Metadata
{
    /**
     * Lazily assembled structured metadata cache.
     */
    private StructuredMetadataCache $structuredCache;

    /** @var list<string> TIFF-EXIF blobs (first is primary). [JPEG, ISO BMFF, TIFF] */
    public array $exifBlobs;

    /** @var list<string> XMP packets (RDF/XML), first is primary. [JPEG, ISO BMFF] */
    public array $xmpBlobs;

    /** @var list<string> Raw ICC APP2 segments in encounter order. [JPEG only] */
    public array $iccSegments;

    /** @var list<JpegAudioStream> EXIF audio streams embedded in JPEG APP2 markers. [JPEG only] */
    public array $jpegAudioStreams;

    /** @var list<IsoBmffUnresolvedItem> ISO BMFF item payloads that could not be resolved. [ISO BMFF only] */
    public array $isoBmffUnresolvedItems;

    /** @var list<int> Tone map item IDs detected in ISO BMFF containers. [ISO BMFF only] */
    public array $tmapItemIds;

    /** @var list<string> IPTC payloads captured from JPEG APP13 segments. [JPEG only] */
    public array $iptcBlobs;

    /**
     * @param list<string>                                         $exifBlobs                 TIFF-EXIF blobs (first is primary). [JPEG, ISO BMFF, TIFF]
     * @param QuickTimeMeta|null                                   $quickTime                 QuickTime metadata extracted from ISO BMFF containers. [ISO BMFF only]
     * @param ParsedExif|null                                      $exifDoc                   Parsed representation of the primary EXIF document. [JPEG, ISO BMFF, TIFF]
     * @param list<string>                                         $xmpBlobs                  XMP packets (RDF/XML), first is primary. [JPEG, ISO BMFF]
     * @param XmpDocument|null                                     $xmpDoc                    Parsed representation of the primary XMP packet. [JPEG, ISO BMFF]
     * @param MakerNotesRecord|null                                $makerNotes                Decoded maker notes metadata for the primary EXIF blob. [JPEG, ISO BMFF, TIFF]
     * @param string|null                                          $iccProfile                Binary ICC profile when available. [JPEG, ISO BMFF]
     * @param list<string>                                         $iccSegments               Raw ICC APP2 segments in encounter order. [JPEG only]
     * @param array<int, string>                                   $flashPixStreams           Concatenated FlashPix extension streams keyed by FPXR contents-list index. [JPEG only]
     * @param MpfDocument|null                                     $mpfDocument               Parsed MPF document derived from APP2 segments. [JPEG only]
     * @param list<JpegAudioStream>                                $jpegAudioStreams          EXIF audio streams embedded in JPEG APP2 markers. [JPEG only]
     * @param int|null                                             $jpegBitsPerSample         Sample precision reported by the JPEG frame header. [JPEG only]
     * @param array<int, array{horizontal:int, vertical:int}>|null $jpegFrameSamplingFactors  Component sampling factors by identifier. [JPEG only]
     * @param array{0:int,1:int}|null                              $jpegYCbCrSubSampling      Derived YCbCr subsampling from the JPEG frame header. [JPEG only]
     * @param int|null                                             $jpegFrameWidth            Frame width reported by the JPEG start of frame marker. [JPEG only]
     * @param int|null                                             $jpegFrameHeight           Frame height reported by the JPEG start of frame marker. [JPEG only]
     * @param string|null                                          $mimeType                  Detected mime type for the source file. [JPEG, ISO BMFF, TIFF]
     * @param int|null                                             $fileSize                  Size of the source file in bytes. [JPEG, ISO BMFF, TIFF]
     * @param string|null                                          $extension                 Lowercase file extension extracted from the path. [JPEG, ISO BMFF, TIFF]
     * @param string|null                                          $digestSha1                Lowercase hexadecimal SHA-1 digest of the payload. [JPEG, ISO BMFF, TIFF]
     * @param string|null                                          $digestMd5                 Lowercase hexadecimal MD5 digest of the payload. [JPEG, ISO BMFF, TIFF]
     * @param IsoBmffItemReferenceMap|null                         $isoBmffItemReferences     ISO BMFF item references extracted from metadata boxes. [ISO BMFF only]
     * @param IsoBmffDataReferenceMap|null                         $isoBmffDataReferences     ISO BMFF data references extracted from metadata boxes. [ISO BMFF only]
     * @param list<IsoBmffUnresolvedItem>                          $isoBmffUnresolvedItems    ISO BMFF item payloads that could not be resolved. [ISO BMFF only]
     * @param int|null                                             $ispeWidth                 Image width in pixels from the ispe box. [ISO BMFF only]
     * @param int|null                                             $ispeHeight                Image height in pixels from the ispe box. [ISO BMFF only]
     * @param list<int>                                            $tmapItemIds               Tone map item IDs detected in ISO BMFF containers. [ISO BMFF only]
     * @param list<string>                                         $iptcBlobs                 IPTC payloads captured from JPEG APP13 segments. [JPEG only]
     * @param IptcDocument|null                                    $iptcDoc                   Parsed IPTC IIM datasets from APP13 payloads. [JPEG only]
     * @param string|null                                          $gainMapBlob               Raw HDR gain map image from a JXL hrgm box. [JXL only]
     * @param XmpParserInterface|null                              $xmpParser                 Injected XMP parser for selective document creation.
     * @param IptcParserInterface|null                             $iptcParser                Injected IPTC parser for selective document creation.
     * @param StructuredMetadataBuilder|null                       $structuredMetadataBuilder Injected builder for structured metadata assembly.
     */
    public function __construct(
        array $exifBlobs,
        public ?QuickTimeMeta $quickTime,
        public ?ParsedExif $exifDoc = null,
        array $xmpBlobs = [],
        public ?XmpDocument $xmpDoc = null,
        public ?MakerNotesRecord $makerNotes = null,
        public ?string $iccProfile = null,
        array $iccSegments = [],
        public array $flashPixStreams = [],
        public ?MpfDocument $mpfDocument = null,
        array $jpegAudioStreams = [],
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
        array $isoBmffUnresolvedItems = [],
        public ?int $ispeWidth = null,
        public ?int $ispeHeight = null,
        array $tmapItemIds = [],
        array $iptcBlobs = [],
        public ?IptcDocument $iptcDoc = null,
        public ?string $gainMapBlob = null,
        private ?XmpParserInterface $xmpParser = null,
        private ?IptcParserInterface $iptcParser = null,
        ?StructuredMetadataBuilder $structuredMetadataBuilder = null,
    ) {
        $this->exifBlobs              = [...$exifBlobs];
        $this->xmpBlobs               = [...$xmpBlobs];
        $this->iccSegments            = [...$iccSegments];
        $this->jpegAudioStreams       = [...$jpegAudioStreams];
        $this->isoBmffUnresolvedItems = [...$isoBmffUnresolvedItems];
        $this->tmapItemIds            = [...$tmapItemIds];
        $this->iptcBlobs              = [...$iptcBlobs];
        $this->structuredCache        = new StructuredMetadataCache(
            $structuredMetadataBuilder ?? StructuredMetadataBuilder::createDefault(),
        );
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

        if ($this->xmpBlobs === [] || !$this->xmpParser instanceof XmpParserInterface) {
            return null;
        }

        $documents = array_map($this->xmpParser->parse(...), $this->xmpBlobs);

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

        if ($this->iptcBlobs === [] || !$this->iptcParser instanceof IptcParserInterface) {
            return null;
        }

        $documents = array_map($this->iptcParser->parse(...), $this->iptcBlobs);

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
