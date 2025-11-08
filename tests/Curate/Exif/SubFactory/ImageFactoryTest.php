<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ImageFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageFactory::class)]
final class ImageFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('imageWidth')->willReturn(6000);
        $exifDoc->method('imageHeight')->willReturn(4000);
        $exifDoc->method('orientation')->willReturn(Orientation::NORMAL);
        $exifDoc->method('bitsPerSample')->willReturn([8, 8, 8]);
        $exifDoc->method('colorSpace')->willReturn(ColorSpace::SRGB);
        $exifDoc->method('interopIndex')->willReturn(null);
        $exifDoc->method('imageUniqueId')->willReturn('ABC123');
        $exifDoc->method('documentName')->willReturn('document.tif');
        $exifDoc->method('imageDescription')->willReturn('Test image');
        $exifDoc->method('imageTitle')->willReturn('Title');
        $exifDoc->method('componentsConfiguration')->willReturn([1, 2, 3, 0]);
        $exifDoc->method('compressedBitsPerPixel')->willReturn(4.0);
        $exifDoc->method('userComment')->willReturn('Test comment');
        $exifDoc->method('userCommentEncodingBestEffort')->willReturn(CharacterEncoding::ASCII);

        $metadata       = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(Orientation::NORMAL, $image->orientation);
        self::assertSame([8, 8, 8], $image->bitsPerSample);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);
        self::assertSame('ABC123', $image->imageUniqueId);
        self::assertSame('document.tif', $image->documentName);
        self::assertSame('Test image', $image->description);
        self::assertSame('Title', $image->title);
        self::assertSame([1, 2, 3, 0], $image->componentsConfiguration);
        self::assertSame(4.0, $image->compressedBitsPerPixel);
        self::assertSame('Test comment', $image->userComment);
        self::assertSame(CharacterEncoding::ASCII, $image->userCommentEncoding);
    }

    #[Test]
    public function fallsBackToJpegDimensions(): void
    {
        $metadata                = new Metadata();
        $metadata->jpegFrameWidth  = 1920;
        $metadata->jpegFrameHeight = 1080;
        $metadata->jpegBitsPerSample = [8];

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1920, $image->width);
        self::assertSame(1080, $image->height);
        self::assertSame([8], $image->bitsPerSample);
    }

    #[Test]
    public function normalizesColorSpaceFromInterop(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('imageWidth')->willReturn(4000);
        $exifDoc->method('imageHeight')->willReturn(3000);
        $exifDoc->method('orientation')->willReturn(null);
        $exifDoc->method('bitsPerSample')->willReturn(null);
        $exifDoc->method('colorSpace')->willReturn(ColorSpace::UNCALIBRATED);
        $exifDoc->method('interopIndex')->willReturn('R98');
        $exifDoc->method('imageUniqueId')->willReturn(null);
        $exifDoc->method('documentName')->willReturn(null);
        $exifDoc->method('imageDescription')->willReturn(null);
        $exifDoc->method('imageTitle')->willReturn(null);
        $exifDoc->method('componentsConfiguration')->willReturn(null);
        $exifDoc->method('compressedBitsPerPixel')->willReturn(null);
        $exifDoc->method('userComment')->willReturn(null);
        $exifDoc->method('userCommentEncodingBestEffort')->willReturn(null);

        $metadata       = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);
    }

    #[Test]
    public function normalizesAdobeRgbFromInterop(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('imageWidth')->willReturn(4000);
        $exifDoc->method('imageHeight')->willReturn(3000);
        $exifDoc->method('orientation')->willReturn(null);
        $exifDoc->method('bitsPerSample')->willReturn(null);
        $exifDoc->method('colorSpace')->willReturn(ColorSpace::UNCALIBRATED);
        $exifDoc->method('interopIndex')->willReturn('r03');
        $exifDoc->method('imageUniqueId')->willReturn(null);
        $exifDoc->method('documentName')->willReturn(null);
        $exifDoc->method('imageDescription')->willReturn(null);
        $exifDoc->method('imageTitle')->willReturn(null);
        $exifDoc->method('componentsConfiguration')->willReturn(null);
        $exifDoc->method('compressedBitsPerPixel')->willReturn(null);
        $exifDoc->method('userComment')->willReturn(null);
        $exifDoc->method('userCommentEncodingBestEffort')->willReturn(null);

        $metadata       = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(ColorSpace::ADOBE_RGB, $image->colorSpace);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertInstanceOf(Image::class, $image);
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->orientation);
        self::assertNull($image->bitsPerSample);
        self::assertNull($image->colorSpace);
    }
}
