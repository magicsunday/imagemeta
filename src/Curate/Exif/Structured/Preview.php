<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\Structured;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Preview as PreviewValue;

/**
 * Indicates the availability of previews and thumbnails from EXIF.
 */
final readonly class Preview
{
    public ?bool $hasThumbnail;

    public ?bool $hasPreview;

    public ?int $previewWidth;

    public ?int $previewHeight;

    public ?ColorSpace $previewColorSpace;

    public ?int $previewBitDepth;

    public ?Compression $previewCompression;

    public ?float $previewScale;

    public ?string $previewEncoding;

    public ?string $previewMimeType;

    public ?int $previewOffset;

    public ?int $previewLength;

    /**
     * @param PreviewValue $preview Raw preview value object describing embedded thumbnails and previews from EXIF.
     */
    public function __construct(PreviewValue $preview)
    {
        $this->hasThumbnail       = $preview->hasThumbnail;
        $this->hasPreview         = $preview->hasPreview;
        $this->previewWidth       = $preview->previewWidth;
        $this->previewHeight      = $preview->previewHeight;
        $this->previewColorSpace  = $preview->previewColorSpace;
        $this->previewBitDepth    = $preview->previewBitDepth;
        $this->previewCompression = $preview->previewCompression;
        $this->previewScale       = $preview->previewScale;
        $this->previewEncoding    = $preview->previewEncoding;
        $this->previewMimeType    = $preview->previewMimeType;
        $this->previewOffset      = $preview->previewOffset;
        $this->previewLength      = $preview->previewLength;
    }

    public function hasThumbnail(): ?bool
    {
        return $this->hasThumbnail;
    }

    public function hasPreview(): ?bool
    {
        return $this->hasPreview;
    }

    public function previewWidth(): ?int
    {
        return $this->previewWidth;
    }

    public function previewHeight(): ?int
    {
        return $this->previewHeight;
    }

    public function previewColorSpace(): ?ColorSpace
    {
        return $this->previewColorSpace;
    }

    public function previewBitDepth(): ?int
    {
        return $this->previewBitDepth;
    }

    public function previewCompression(): ?Compression
    {
        return $this->previewCompression;
    }

    public function previewScale(): ?float
    {
        return $this->previewScale;
    }

    public function previewEncoding(): ?string
    {
        return $this->previewEncoding;
    }

    public function previewMimeType(): ?string
    {
        return $this->previewMimeType;
    }

    public function previewOffset(): ?int
    {
        return $this->previewOffset;
    }

    public function previewLength(): ?int
    {
        return $this->previewLength;
    }
}
