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
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image as ImageValue;

/**
 * Holds EXIF image attributes without QuickTime fallbacks.
 *
 * @deprecated Internal bridging wrapper scheduled for removal after Milestone M1.
 *             Use MagicSunday\ImageMeta\Curate\Structured\MediaMetadata for curated image data.
 */
final readonly class Image
{
    public ?int $width;

    public ?int $height;

    public ?Orientation $orientation;

    public ?int $bitsPerSample;

    public ?ColorSpace $colorSpace;

    public ?string $imageUniqueId;

    public ?int $imageNumber;

    public ?string $documentName;

    public ?string $description;

    public ?string $title;

    /**
     * @var list<int>|null
     */
    public ?array $componentsConfiguration;

    public ?float $compressedBitsPerPixel;

    public ?int $interlace;

    public ?string $userComment;

    public ?string $userCommentEncoding;

    /**
     * @param ImageValue $image Raw image value object sourced directly from EXIF, already normalised for enums and lists.
     */
    public function __construct(ImageValue $image)
    {
        $this->width                   = $image->width;
        $this->height                  = $image->height;
        $this->orientation             = $image->orientation;
        $this->bitsPerSample           = $image->bitsPerSample;
        $this->colorSpace              = $image->colorSpace;
        $this->imageUniqueId           = $image->imageUniqueId;
        $this->imageNumber             = $image->imageNumber;
        $this->documentName            = $image->documentName;
        $this->description             = $image->description;
        $this->title                   = $image->title;
        $this->componentsConfiguration = $image->componentsConfiguration;
        $this->compressedBitsPerPixel  = $image->compressedBitsPerPixel;
        $this->interlace               = $image->interlace;
        $this->userComment             = $image->userComment;
        $this->userCommentEncoding     = $image->userCommentEncoding;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function orientation(): ?Orientation
    {
        return $this->orientation;
    }

    public function bitsPerSample(): ?int
    {
        return $this->bitsPerSample;
    }

    public function colorSpace(): ?ColorSpace
    {
        return $this->colorSpace;
    }

    public function imageUniqueId(): ?string
    {
        return $this->imageUniqueId;
    }

    public function imageNumber(): ?int
    {
        return $this->imageNumber;
    }

    public function documentName(): ?string
    {
        return $this->documentName;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /**
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        return $this->componentsConfiguration;
    }

    public function compressedBitsPerPixel(): ?float
    {
        return $this->compressedBitsPerPixel;
    }

    public function interlace(): ?int
    {
        return $this->interlace;
    }

    public function userComment(): ?string
    {
        return $this->userComment;
    }

    public function userCommentEncoding(): ?string
    {
        return $this->userCommentEncoding;
    }
}
