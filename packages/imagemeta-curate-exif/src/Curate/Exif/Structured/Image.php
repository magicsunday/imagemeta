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
    }
}
