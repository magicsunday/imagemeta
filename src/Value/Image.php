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
     * @param int|null         $width         Final image width in pixels.
     * @param int|null         $height        Final image height in pixels.
     * @param Orientation|null $orientation   Image orientation when stored on disk.
     * @param int|null         $bitsPerSample Bits per colour channel.
     * @param ColorSpace|null  $colorSpace    Colour space used for the pixel data.
     * @param string|null      $imageUniqueId Globally unique image identifier.
     * @param string|null      $documentName  Optional document or file name derived from metadata sources.
     * @param string|null      $description   Free-form description provided by the camera.
     */
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?Orientation $orientation,
        public ?int $bitsPerSample,
        public ?ColorSpace $colorSpace,
        public ?string $imageUniqueId,
        public ?string $documentName,
        public ?string $description,
    ) {
    }
}
