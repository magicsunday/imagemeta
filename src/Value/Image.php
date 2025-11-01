<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;

/**
 * Encapsulates image level metadata.
 */
final readonly class Image
{
    /**
     * @param int|null         $width                   Final image width in pixels.
     * @param int|null         $height                  Final image height in pixels.
     * @param Orientation|null $orientation             Image orientation when stored on disk; non-standard values yield
     *                                                  Orientation::UNKNOWN.
     * @param int|null         $bitsPerSample           Bits per colour channel.
     * @param ColorSpace|null  $colorSpace              Colour space used for the pixel data.
     * @param string|null      $imageUniqueId           Globally unique image identifier.
     * @param int|null         $imageNumber             Sequential number of the image as reported by the camera.
     * @param string|null      $documentName            Optional document or file name derived from metadata sources.
     * @param string|null      $description             Free-form description provided by the camera.
     * @param string|null      $title                   Human-readable title provided by the camera or metadata.
     * @param list<int>|null   $componentsConfiguration Layout of the colour components for each pixel sample.
     * @param float|null       $compressedBitsPerPixel  Average bits per pixel after compression.
     * @param int|null         $interlace               Interlace indicator reported by the camera.
     * @param string|null      $userComment             Arbitrary user comment stored by the device.
     * @param string|null      $userCommentEncoding     Declared encoding for the user comment payload.
     */
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?Orientation $orientation,
        public ?int $bitsPerSample,
        public ?ColorSpace $colorSpace,
        public ?string $imageUniqueId,
        public ?int $imageNumber,
        public ?string $documentName,
        public ?string $description,
        public ?string $title,
        public ?array $componentsConfiguration,
        public ?float $compressedBitsPerPixel,
        public ?int $interlace,
        public ?string $userComment,
        public ?string $userCommentEncoding,
    ) {
    }
}
