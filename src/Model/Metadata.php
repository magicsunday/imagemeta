<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;

/**
 * Aggregates extracted metadata blobs alongside parsed representations.
 */
final class Metadata
{
    /**
     * @var list<string>
     */
    public readonly array $exifBlobs;

    public readonly ?QuickTimeMeta $quickTime;

    public readonly ?ExifDocument $exifDoc;

    /**
     * @var list<string>
     */
    public readonly array $xmpBlobs;

    public readonly ?XmpDocument $xmpDoc;

    public readonly ?MakerNotesMetadata $makerNotes;

    public readonly ?string $iccProfile;

    /**
     * @var list<string>
     */
    public readonly array $iccSegments;

    /**
     * @var array<int, string>
     */
    public readonly array $flashPixStreams;

    public readonly ?MpfDocument $mpfDocument;

    /**
     * @var list<JpegAudioStream>
     */
    public readonly array $jpegAudioStreams;

    public readonly ?int $jpegBitsPerSample;

    /** @var array<int, array{horizontal:int, vertical:int}>|null */
    public readonly ?array $jpegFrameSamplingFactors;

    /** @var array{0:int,1:int}|null */
    public readonly ?array $jpegYCbCrSubSampling;

    public readonly ?int $jpegFrameWidth;

    public readonly ?int $jpegFrameHeight;

    public readonly ?string $mimeType;

    public readonly ?int $fileSize;

    public readonly ?string $extension;

    public readonly ?string $digestSha1;

    public readonly ?string $digestMd5;

    private ?StructuredMetadata $structured = null;

    /**
     * @param list<string>                                         $exifBlobs                TIFF‑EXIF blobs (first is primary)
     * @param QuickTimeMeta|null                                   $quickTime                QuickTime metadata extracted from ISO BMFF containers.
     * @param ExifDocument|null                                    $exifDoc                  Parsed representation of the primary EXIF document.
     * @param list<string>                                         $xmpBlobs                 XMP packets (RDF/XML), first is primary
     * @param XmpDocument|null                                     $xmpDoc                   Parsed representation of the primary XMP packet.
     * @param MakerNotesMetadata|null                              $makerNotes               Decoded maker notes metadata for the primary EXIF blob.
     * @param string|null                                          $iccProfile               Binary ICC profile when available.
     * @param list<string>                                         $iccSegments              Raw ICC APP2 segments in encounter order.
     * @param array<int, string>                                   $flashPixStreams          Concatenated FlashPix extension streams keyed by identifier.
     * @param MpfDocument|null                                     $mpfDocument              Parsed MPF document derived from APP2 segments.
     * @param list<JpegAudioStream>                                $jpegAudioStreams         EXIF audio streams embedded in JPEG APP2 markers.
     * @param int|null                                             $jpegBitsPerSample        Sample precision reported by the JPEG frame header.
     * @param array<int, array{horizontal:int, vertical:int}>|null $jpegFrameSamplingFactors Component sampling factors by
     *                                                                                       identifier.
     * @param array{0:int,1:int}|null                              $jpegYCbCrSubSampling     Derived YCbCr subsampling from the JPEG frame header.
     * @param int|null                                             $jpegFrameWidth           Frame width reported by the JPEG start of frame marker.
     * @param int|null                                             $jpegFrameHeight          Frame height reported by the JPEG start of frame marker.
     * @param string|null                                          $mimeType                 Detected mime type for the source file.
     * @param int|null                                             $fileSize                 Size of the source file in bytes.
     * @param string|null                                          $extension                Lowercase file extension extracted from the path.
     * @param string|null                                          $digestSha1               Lowercase hexadecimal SHA-1 digest of the payload.
     * @param string|null                                          $digestMd5                Lowercase hexadecimal MD5 digest of the payload.
     */
    public function __construct(
        array $exifBlobs,
        ?QuickTimeMeta $quickTime,
        ?ExifDocument $exifDoc = null,
        array $xmpBlobs = [],
        ?XmpDocument $xmpDoc = null,
        ?MakerNotesMetadata $makerNotes = null,
        ?string $iccProfile = null,
        array $iccSegments = [],
        array $flashPixStreams = [],
        ?MpfDocument $mpfDocument = null,
        array $jpegAudioStreams = [],
        ?int $jpegBitsPerSample = null,
        ?array $jpegFrameSamplingFactors = null,
        ?array $jpegYCbCrSubSampling = null,
        ?string $mimeType = null,
        ?int $fileSize = null,
        ?string $extension = null,
        ?string $digestSha1 = null,
        ?string $digestMd5 = null,
        ?int $jpegFrameWidth = null,
        ?int $jpegFrameHeight = null,
    ) {
        $this->exifBlobs                = $exifBlobs;
        $this->quickTime                = $quickTime;
        $this->exifDoc                  = $exifDoc;
        $this->xmpBlobs                 = $xmpBlobs;
        $this->xmpDoc                   = $xmpDoc;
        $this->makerNotes               = $makerNotes;
        $this->iccProfile               = $iccProfile;
        $this->iccSegments              = $iccSegments;
        $this->flashPixStreams          = $flashPixStreams;
        $this->mpfDocument              = $mpfDocument;
        $this->jpegAudioStreams         = $jpegAudioStreams;
        $this->jpegBitsPerSample        = $jpegBitsPerSample;
        $this->jpegFrameSamplingFactors = $jpegFrameSamplingFactors;
        $this->jpegYCbCrSubSampling     = $jpegYCbCrSubSampling;
        $this->mimeType                 = $mimeType;
        $this->fileSize                 = $fileSize;
        $this->extension                = $extension;
        $this->digestSha1               = $digestSha1;
        $this->digestMd5                = $digestMd5;
        $this->jpegFrameWidth           = $jpegFrameWidth;
        $this->jpegFrameHeight          = $jpegFrameHeight;
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

    /**
     * Returns curated structured metadata derived lazily from the available sources.
     */
    public function structured(): StructuredMetadata
    {
        if (!$this->structured instanceof StructuredMetadata) {
            $assembler       = new ExifAssembler();
            $this->structured = $assembler->assemble($this);
        }

        return $this->structured;
    }
}
