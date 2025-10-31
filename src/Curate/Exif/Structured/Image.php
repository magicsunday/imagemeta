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
 * @deprecated since milestone M4. This transitional wrapper will be removed in the
 *             following release. Consume the underlying Value objects directly instead.
 */
final readonly class Image
{
    public function __construct(private ImageValue $image)
    {
    }

    public function value(): ImageValue
    {
        return $this->image;
    }

    public function width(): ?int
    {
        return $this->image->width;
    }

    public function height(): ?int
    {
        return $this->image->height;
    }

    public function orientation(): ?Orientation
    {
        return $this->image->orientation;
    }

    public function bitsPerSample(): ?int
    {
        return $this->image->bitsPerSample;
    }

    public function colorSpace(): ?ColorSpace
    {
        return $this->image->colorSpace;
    }

    public function imageUniqueId(): ?string
    {
        return $this->image->imageUniqueId;
    }

    public function imageNumber(): ?int
    {
        return $this->image->imageNumber;
    }

    public function documentName(): ?string
    {
        return $this->image->documentName;
    }

    public function description(): ?string
    {
        return $this->image->description;
    }

    public function title(): ?string
    {
        return $this->image->title;
    }

    /**
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        return $this->image->componentsConfiguration;
    }

    public function compressedBitsPerPixel(): ?float
    {
        return $this->image->compressedBitsPerPixel;
    }

    public function interlace(): ?int
    {
        return $this->image->interlace;
    }

    public function userComment(): ?string
    {
        return $this->image->userComment;
    }

    public function userCommentEncoding(): ?string
    {
        return $this->image->userCommentEncoding;
    }
}
