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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

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

    /** @var list<string> */
    private array $iptcBlobs = [];

    private ?IptcDocument $iptcDoc = null;

    private ?string $mimeType = null;

    private ?int $fileSize = null;

    private ?string $extension = null;

    private ?string $digestSha1 = null;

    private ?string $digestMd5 = null;

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
     * Configures JPEG segment payloads (ICC, FlashPix, MPF, audio).
     *
     * @param string|null           $iccProfile       Binary ICC profile.
     * @param list<string>          $iccSegments      Raw ICC APP2 segments in encounter order.
     * @param array<int, string>    $flashPixStreams  Concatenated FlashPix extension streams.
     * @param MpfDocument|null      $mpfDocument      Parsed MPF document.
     * @param list<JpegAudioStream> $jpegAudioStreams EXIF audio streams embedded in JPEG APP2 markers.
     */
    public function withJpegSegments(
        ?string $iccProfile = null,
        array $iccSegments = [],
        array $flashPixStreams = [],
        ?MpfDocument $mpfDocument = null,
        array $jpegAudioStreams = [],
    ): self {
        $this->iccProfile       = $iccProfile;
        $this->iccSegments      = $iccSegments;
        $this->flashPixStreams  = $flashPixStreams;
        $this->mpfDocument      = $mpfDocument;
        $this->jpegAudioStreams = $jpegAudioStreams;

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
     */
    public function withIsoBmff(
        ?IsoBmffItemReferenceMap $itemReferences = null,
        ?IsoBmffDataReferenceMap $dataReferences = null,
        array $unresolvedItems = [],
    ): self {
        $this->isoBmffItemReferences  = $itemReferences;
        $this->isoBmffDataReferences  = $dataReferences;
        $this->isoBmffUnresolvedItems = $unresolvedItems;

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
     * Configures file-level identity and integrity fields.
     *
     * @param string|null $mimeType  Detected MIME type for the source file.
     * @param int|null    $fileSize  Size of the source file in bytes.
     * @param string|null $extension Lowercase file extension extracted from the path.
     * @param string|null $sha1      Lowercase hexadecimal SHA-1 digest.
     * @param string|null $md5       Lowercase hexadecimal MD5 digest.
     */
    public function withFileIdentity(
        ?string $mimeType = null,
        ?int $fileSize = null,
        ?string $extension = null,
        ?string $sha1 = null,
        ?string $md5 = null,
    ): self {
        $this->mimeType   = $mimeType;
        $this->fileSize   = $fileSize;
        $this->extension  = $extension;
        $this->digestSha1 = $sha1;
        $this->digestMd5  = $md5;

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
            digestSha1: $this->digestSha1,
            digestMd5: $this->digestMd5,
            jpegFrameWidth: $this->jpegFrameWidth,
            jpegFrameHeight: $this->jpegFrameHeight,
            isoBmffItemReferences: $this->isoBmffItemReferences,
            isoBmffDataReferences: $this->isoBmffDataReferences,
            isoBmffUnresolvedItems: $this->isoBmffUnresolvedItems,
            iptcBlobs: $this->iptcBlobs,
            iptcDoc: $this->iptcDoc,
        );
    }
}
