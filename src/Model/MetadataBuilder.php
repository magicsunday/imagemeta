<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

// jscpd:ignore-start
use Closure;
use MagicSunday\ImageMeta\Contract\IptcParserInterface;
use MagicSunday\ImageMeta\Contract\XmpParserInterface;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\Jpeg\JfifSegment;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Riff\NikonCameraTags;
use MagicSunday\ImageMeta\Model\Riff\RiffAviHeader;
use MagicSunday\ImageMeta\Model\Riff\RiffExifChunk;
use MagicSunday\ImageMeta\Model\Riff\RiffInfo;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\StructuredMetadata;

// jscpd:ignore-end

/**
 * Fluent builder for assembling {@see Metadata} aggregates from domain-specific groups.
 *
 * @phpstan-type SamplingFactors = array<int, array{horizontal:int, vertical:int}>
 */
final class MetadataBuilder
{
    /** @var list<string> */
    private array $exifBlobs = [];

    private ?ParsedExif $exifDoc = null;

    private ?MakerNotesRecord $makerNotes = null;

    /** @var list<string> */
    private array $xmpBlobs = [];

    private ?XmpDocument $xmpDoc = null;

    private ?QuickTimeMeta $quickTime = null;

    private ?string $iccProfile = null;

    /** @var list<string> */
    private array $iccSegments = [];

    /** @var array<int, string> */
    private array $flashPixStreams = [];

    private ?MpfDocument $mpfDocument = null;

    /** @var list<JpegAudioStream> */
    private array $jpegAudioStreams = [];

    private ?JfifSegment $jfifSegment = null;

    private ?int $jpegBitsPerSample = null;

    /** @var SamplingFactors|null */
    private ?array $jpegFrameSamplingFactors = null;

    /** @var array{0:int,1:int}|null */
    private ?array $jpegYCbCrSubSampling = null;

    private ?int $jpegFrameWidth = null;

    private ?int $jpegFrameHeight = null;

    private ?IsoBmffItemReferenceMap $isoBmffItemReferences = null;

    private ?IsoBmffDataReferenceMap $isoBmffDataReferences = null;

    /** @var list<IsoBmffUnresolvedItem> */
    private array $isoBmffUnresolvedItems = [];

    private ?int $ispeWidth = null;

    private ?int $ispeHeight = null;

    /** @var list<int> */
    private array $tmapItemIds = [];

    /** @var list<string> */
    private array $iptcBlobs = [];

    private ?IptcDocument $iptcDoc = null;

    private ?string $gainMapBlob = null;

    private ?RiffInfo $riffInfo = null;

    private ?RiffAviHeader $riffAviHeader = null;

    private ?RiffExifChunk $riffExif = null;

    private ?NikonCameraTags $nikonCameraTags = null;

    private ?string $mimeType = null;

    private ?int $fileSize = null;

    private ?string $extension = null;

    private ?string $digestSha256 = null;

    private ?XmpParserInterface $xmpParser = null;

    private ?IptcParserInterface $iptcParser = null;

    /**
     * @param (Closure(Metadata):StructuredMetadata)|null $structuredResolver Closure that converts a Metadata aggregate
     *                                                                        into typed StructuredMetadata. When null,
     *                                                                        the Metadata instance will not support
     *                                                                        structured() calls unless a resolver is
     *                                                                        provided at the Metadata level.
     */
    public function __construct(private readonly ?Closure $structuredResolver = null)
    {
    }

    /**
     * Configures parser instances for selective document creation.
     *
     * @param XmpParserInterface  $xmpParser  XMP parser used by {@see Metadata::selectiveXmpDocument()}.
     * @param IptcParserInterface $iptcParser IPTC parser used by {@see Metadata::selectiveIptcDocument()}.
     */
    public function withParsers(XmpParserInterface $xmpParser, IptcParserInterface $iptcParser): self
    {
        $this->xmpParser  = $xmpParser;
        $this->iptcParser = $iptcParser;

        return $this;
    }

    /**
     * Configures EXIF data sources.
     *
     * @param list<string>          $blobs      TIFF-EXIF blobs (first is primary).
     * @param ParsedExif|null       $document   Parsed representation of the primary EXIF document.
     * @param MakerNotesRecord|null $makerNotes Decoded maker notes metadata.
     */
    public function withExif(array $blobs, ?ParsedExif $document = null, ?MakerNotesRecord $makerNotes = null): self
    {
        $this->exifBlobs  = $blobs;
        $this->exifDoc    = $document;
        $this->makerNotes = $makerNotes;

        return $this;
    }

    /**
     * Configures XMP data sources.
     *
     * @param list<string>     $blobs    XMP packets (RDF/XML), first is primary.
     * @param XmpDocument|null $document Parsed representation of the primary XMP packet.
     */
    public function withXmp(array $blobs, ?XmpDocument $document = null): self
    {
        $this->xmpBlobs = $blobs;
        $this->xmpDoc   = $document;

        return $this;
    }

    /**
     * Configures QuickTime metadata extracted from ISO BMFF containers.
     */
    public function withQuickTime(?QuickTimeMeta $quickTime): self
    {
        $this->quickTime = $quickTime;

        return $this;
    }

    /**
     * Configures a standalone ICC color profile (e.g. from TIFF tag 34675).
     *
     * @param string|null $iccProfile Binary ICC profile data.
     */
    public function withIccProfile(?string $iccProfile): self
    {
        if ($iccProfile !== null) {
            $this->iccProfile = $iccProfile;
        }

        return $this;
    }

    /**
     * Configures JPEG segment payloads (ICC, FlashPix, MPF, audio, JFIF).
     *
     * @param string|null           $iccProfile       Binary ICC profile.
     * @param list<string>          $iccSegments      Raw ICC APP2 segments in encounter order.
     * @param array<int, string>    $flashPixStreams  Concatenated FlashPix extension streams.
     * @param MpfDocument|null      $mpfDocument      Parsed MPF document.
     * @param list<JpegAudioStream> $jpegAudioStreams EXIF audio streams embedded in JPEG APP2 markers.
     * @param JfifSegment|null      $jfifSegment      Parsed JFIF APP0 segment.
     */
    public function withJpegSegments(
        ?string $iccProfile = null,
        array $iccSegments = [],
        array $flashPixStreams = [],
        ?MpfDocument $mpfDocument = null,
        array $jpegAudioStreams = [],
        ?JfifSegment $jfifSegment = null,
    ): self {
        $this->iccProfile       = $iccProfile;
        $this->iccSegments      = $iccSegments;
        $this->flashPixStreams  = $flashPixStreams;
        $this->mpfDocument      = $mpfDocument;
        $this->jpegAudioStreams = $jpegAudioStreams;
        $this->jfifSegment      = $jfifSegment;

        return $this;
    }

    /**
     * Configures JPEG frame header data.
     *
     * @param int|null                $width           Frame width from JPEG start of frame marker.
     * @param int|null                $height          Frame height from JPEG start of frame marker.
     * @param int|null                $bitsPerSample   Sample precision from JPEG frame header.
     * @param SamplingFactors|null    $samplingFactors Component sampling factors by identifier.
     * @param array{0:int,1:int}|null $subSampling     Derived YCbCr subsampling from the JPEG frame header.
     */
    public function withJpegFrame(
        ?int $width = null,
        ?int $height = null,
        ?int $bitsPerSample = null,
        ?array $samplingFactors = null,
        ?array $subSampling = null,
    ): self {
        $this->jpegFrameWidth           = $width;
        $this->jpegFrameHeight          = $height;
        $this->jpegBitsPerSample        = $bitsPerSample;
        $this->jpegFrameSamplingFactors = $samplingFactors;
        $this->jpegYCbCrSubSampling     = $subSampling;

        return $this;
    }

    /**
     * Configures ISO BMFF item and data references.
     *
     * @param IsoBmffItemReferenceMap|null $itemReferences  Item references extracted from metadata boxes.
     * @param IsoBmffDataReferenceMap|null $dataReferences  Data references extracted from metadata boxes.
     * @param list<IsoBmffUnresolvedItem>  $unresolvedItems Item payloads that could not be resolved.
     * @param int|null                     $ispeWidth       Image width from ispe box.
     * @param int|null                     $ispeHeight      Image height from ispe box.
     * @param string|null                  $iccProfile      Binary ICC profile from colr box.
     * @param list<int>                    $tmapItemIds     Tone map item IDs detected in infe entries.
     */
    public function withIsoBmff(
        ?IsoBmffItemReferenceMap $itemReferences = null,
        ?IsoBmffDataReferenceMap $dataReferences = null,
        array $unresolvedItems = [],
        ?int $ispeWidth = null,
        ?int $ispeHeight = null,
        ?string $iccProfile = null,
        array $tmapItemIds = [],
    ): self {
        $this->isoBmffItemReferences  = $itemReferences;
        $this->isoBmffDataReferences  = $dataReferences;
        $this->isoBmffUnresolvedItems = $unresolvedItems;
        $this->ispeWidth              = $ispeWidth;
        $this->ispeHeight             = $ispeHeight;
        $this->tmapItemIds            = $tmapItemIds;

        if ($iccProfile !== null) {
            $this->iccProfile = $iccProfile;
        }

        return $this;
    }

    /**
     * Configures IPTC data sources.
     *
     * @param list<string>      $blobs    IPTC payloads from JPEG APP13 segments.
     * @param IptcDocument|null $document Parsed IPTC IIM datasets.
     */
    public function withIptc(array $blobs, ?IptcDocument $document = null): self
    {
        $this->iptcBlobs = $blobs;
        $this->iptcDoc   = $document;

        return $this;
    }

    /**
     * Configures the raw HDR gain map blob from a JXL hrgm box.
     *
     * @param string|null $blob Raw gain map image data.
     */
    public function withGainMapBlob(?string $blob): self
    {
        $this->gainMapBlob = $blob;

        return $this;
    }

    /**
     * Configures RIFF-specific metadata from AVI containers.
     *
     * @param RiffInfo|null        $info            INFO chunk metadata.
     * @param RiffAviHeader|null   $aviHeader       Parsed AVI main header.
     * @param RiffExifChunk|null   $riffExif        RIFF-native EXIF sub-chunk fields.
     * @param NikonCameraTags|null $nikonCameraTags Nikon camera tags from ncdt/nctg chunk.
     */
    public function withRiff(
        ?RiffInfo $info = null,
        ?RiffAviHeader $aviHeader = null,
        ?RiffExifChunk $riffExif = null,
        ?NikonCameraTags $nikonCameraTags = null,
    ): self {
        $this->riffInfo        = $info;
        $this->riffAviHeader   = $aviHeader;
        $this->riffExif        = $riffExif;
        $this->nikonCameraTags = $nikonCameraTags;

        return $this;
    }

    /**
     * Configures file-level identity and integrity fields.
     *
     * @param string|null $mimeType  Detected MIME type for the source file.
     * @param int|null    $fileSize  Size of the source file in bytes.
     * @param string|null $extension Lowercase file extension extracted from the path.
     * @param string|null $sha256    Lowercase hexadecimal SHA-256 digest.
     */
    public function withFileIdentity(
        ?string $mimeType = null,
        ?int $fileSize = null,
        ?string $extension = null,
        ?string $sha256 = null,
    ): self {
        $this->mimeType     = $mimeType;
        $this->fileSize     = $fileSize;
        $this->extension    = $extension;
        $this->digestSha256 = $sha256;

        return $this;
    }

    /**
     * Builds the immutable Metadata aggregate from accumulated state.
     */
    public function build(): Metadata
    {
        return new Metadata(
            exifBlobs: $this->exifBlobs,
            quickTime: $this->quickTime,
            exifDoc: $this->exifDoc,
            xmpBlobs: $this->xmpBlobs,
            xmpDoc: $this->xmpDoc,
            makerNotes: $this->makerNotes,
            iccProfile: $this->iccProfile,
            iccSegments: $this->iccSegments,
            flashPixStreams: $this->flashPixStreams,
            mpfDocument: $this->mpfDocument,
            jpegAudioStreams: $this->jpegAudioStreams,
            jpegBitsPerSample: $this->jpegBitsPerSample,
            jpegFrameSamplingFactors: $this->jpegFrameSamplingFactors,
            jpegYCbCrSubSampling: $this->jpegYCbCrSubSampling,
            mimeType: $this->mimeType,
            fileSize: $this->fileSize,
            extension: $this->extension,
            digestSha256: $this->digestSha256,
            jpegFrameWidth: $this->jpegFrameWidth,
            jpegFrameHeight: $this->jpegFrameHeight,
            isoBmffItemReferences: $this->isoBmffItemReferences,
            isoBmffDataReferences: $this->isoBmffDataReferences,
            isoBmffUnresolvedItems: $this->isoBmffUnresolvedItems,
            ispeWidth: $this->ispeWidth,
            ispeHeight: $this->ispeHeight,
            tmapItemIds: $this->tmapItemIds,
            iptcBlobs: $this->iptcBlobs,
            iptcDoc: $this->iptcDoc,
            gainMapBlob: $this->gainMapBlob,
            riffInfo: $this->riffInfo,
            riffAviHeader: $this->riffAviHeader,
            riffExif: $this->riffExif,
            nikonCameraTags: $this->nikonCameraTags,
            jfifSegment: $this->jfifSegment,
            xmpParser: $this->xmpParser,
            iptcParser: $this->iptcParser,
            structuredResolver: $this->structuredResolver,
        );
    }
}
