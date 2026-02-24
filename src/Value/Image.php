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
     * Creates an image metadata value object.
     *
     * @param int|null         $width                   Final image width in pixels.
     * @param int|null         $height                  Final image height in pixels.
     * @param Orientation|null $orientation             Image orientation when stored on disk; non-standard values yield
     *                                                  Orientation::Unknown.
     * @param int|null         $bitsPerSample           Bits per colour channel.
     * @param ColorSpace|null  $colorSpace              Colour space used for the pixel data.
     * @param string|null      $imageUniqueId           Globally unique image identifier.
     * @param string|null      $documentName            Optional document or file name derived from metadata sources.
     * @param string|null      $description             Free-form description provided by the camera.
     * @param string|null      $title                   Human-readable title provided by the camera or metadata.
     * @param list<int>|null   $componentsConfiguration Layout of the colour components for each pixel sample.
     * @param float|null       $compressedBitsPerPixel  Average bits per pixel after compression.
     * @param UserComment|null $comment                 User comment with encoding information.
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?Orientation $orientation = null,
        public ?int $bitsPerSample = null,
        public ?ColorSpace $colorSpace = null,
        public ?string $imageUniqueId = null,
        public ?string $documentName = null,
        public ?string $description = null,
        public ?string $title = null,
        public ?array $componentsConfiguration = null,
        public ?float $compressedBitsPerPixel = null,
        public ?UserComment $comment = null,
    ) {
    }
}
