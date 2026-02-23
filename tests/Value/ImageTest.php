<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\UserComment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Image value object for dimensions, orientation, and descriptive fields.
 * It verifies color space, bits-per-sample, and component arrays are preserved.
 * The suite covers document/title/description metadata and unique IDs.
 * This ensures image-level metadata remains consistent for consumers.
 *
 * @internal
 */
#[UsesClass(UserComment::class)]
#[CoversClass(Image::class)]
final class ImageTest extends TestCase
{
    /**
     * Exposes image dimensions and related metadata fields.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function providesDimensionAndMetadataValues(): void
    {
        $components = [1, 2, 3, 0];

        $comment = new UserComment(
            value: 'Captured with tripod',
            encoding: 'ASCII',
        );

        $image = new Image(
            width: 6000,
            height: 4000,
            orientation: Orientation::RIGHT_TOP,
            bitsPerSample: 14,
            colorSpace: ColorSpace::SRGB,
            imageUniqueId: 'unique-id',
            documentName: 'IMG_0042',
            description: 'Sunrise',
            title: 'Sunrise over Mountains',
            componentsConfiguration: $components,
            compressedBitsPerPixel: 3.2,
            comment: $comment,
        );

        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(Orientation::RIGHT_TOP, $image->orientation);
        self::assertSame(14, $image->bitsPerSample);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);
        self::assertSame('unique-id', $image->imageUniqueId);
        self::assertSame('IMG_0042', $image->documentName);
        self::assertSame('Sunrise', $image->description);
        self::assertSame('Sunrise over Mountains', $image->title);
        self::assertSame($components, $image->componentsConfiguration);
        self::assertSame(3.2, $image->compressedBitsPerPixel);
        self::assertNotNull($image->comment);
        self::assertSame('Captured with tripod', $image->comment->value);
        self::assertSame('ASCII', $image->comment->encoding);
    }
}
