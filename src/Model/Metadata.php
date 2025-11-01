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
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;

/**
 * Aggregates extracted EXIF payloads alongside parsed representations and container hints.
 */
final readonly class Metadata
{
    /**
     * @param list<string>                                         $exifBlobs                TIFF-EXIF blobs extracted from the container.
     * @param array<int, array{horizontal:int, vertical:int}>|null $jpegFrameSamplingFactors Component sampling factors keyed by component id.
     * @param array{0:int,1:int}|null                              $jpegYCbCrSubSampling     Derived YCbCr subsampling from the JPEG frame header.
     */
    public function __construct(
        public array $exifBlobs,
        public ?ParsedExif $exifDoc = null,
        public ?int $jpegBitsPerSample = null,
        public ?array $jpegFrameSamplingFactors = null,
        public ?array $jpegYCbCrSubSampling = null,
        public ?int $jpegFrameWidth = null,
        public ?int $jpegFrameHeight = null,
        public ?string $mimeType = null,
        public ?int $fileSize = null,
        public ?string $extension = null,
        public ?string $digestSha1 = null,
        public ?string $digestMd5 = null,
        private StructuredMetadataCache $structuredCache = new StructuredMetadataCache(),
    ) {
    }

    /**
     * Returns curated structured metadata derived lazily from the available sources.
     */
    public function structured(): StructuredMetadata
    {
        return $this->structuredCache->resolve($this);
    }
}
