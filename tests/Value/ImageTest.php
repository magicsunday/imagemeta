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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Image::class)]
final class ImageTest extends TestCase
{
    #[Test]
    public function providesDimensionAndMetadataValues(): void
    {
        $components = [1, 2, 3, 0];

        $image = new Image(
            width: 6000,
            height: 4000,
            orientation: Orientation::RIGHT_TOP,
            bitsPerSample: 14,
            colorSpace: ColorSpace::ADOBE_RGB,
            imageUniqueId: 'unique-id',
            documentName: 'IMG_0042',
            description: 'Sunrise',
            title: 'Sunrise over Mountains',
            componentsConfiguration: $components,
            compressedBitsPerPixel: 3.2,
            userComment: 'Captured with tripod',
            userCommentEncoding: 'ASCII',
        );

        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(Orientation::RIGHT_TOP, $image->orientation);
        self::assertSame(14, $image->bitsPerSample);
        self::assertSame(ColorSpace::ADOBE_RGB, $image->colorSpace);
        self::assertSame('unique-id', $image->imageUniqueId);
        self::assertSame('IMG_0042', $image->documentName);
        self::assertSame('Sunrise', $image->description);
        self::assertSame('Sunrise over Mountains', $image->title);
        self::assertSame($components, $image->componentsConfiguration);
        self::assertSame(3.2, $image->compressedBitsPerPixel);
        self::assertSame('Captured with tripod', $image->userComment);
        self::assertSame('ASCII', $image->userCommentEncoding);
    }
}
