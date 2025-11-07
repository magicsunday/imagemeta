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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Thumbnail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Thumbnail::class)]
final class PreviewTest extends TestCase
{
    #[Test]
    public function exposesPreviewAndThumbnailDetails(): void
    {
        $preview = new Preview(
            // Thumbnail parameters (IFD1)
            hasThumbnail: true,
            thumbnailOffset: 512,
            thumbnailLength: 1024,
            thumbnailCompression: Compression::JPEG,
            thumbnailTileWidth: 256,
            thumbnailTileLength: 256,
            thumbnailTileOffsets: [512],
            thumbnailTileByteCounts: [1024],
            thumbnailStripOffsets: [512],
            thumbnailStripByteCounts: [1024],
            // Preview parameters (EXIF 3.0)
            hasPreview: true,
            previewOffset: 4096,
            previewLength: 8192,
            previewWidth: 1024,
            previewHeight: 768,
            previewColorSpace: ColorSpace::SRGB,
            previewBitDepth: 8,
            previewCompression: Compression::JPEG,
            previewScale: 0.5,
            previewEncoding: 'JPEG',
            previewMimeType: 'image/jpeg',
            previewTileOffsets: [4096],
            previewTileByteCounts: [8192],
            previewStripOffsets: [4096],
            previewStripByteCounts: [8192],
        );

        self::assertTrue($preview->hasThumbnail);
        self::assertTrue($preview->hasPreview);
        self::assertSame(1024, $preview->previewWidth);
        self::assertSame(768, $preview->previewHeight);
        self::assertSame(ColorSpace::SRGB, $preview->previewColorSpace);
        self::assertSame(8, $preview->previewBitDepth);
        self::assertSame(Compression::JPEG, $preview->previewCompression);
        self::assertSame(0.5, $preview->previewScale);
        self::assertSame('JPEG', $preview->previewEncoding);
        self::assertSame('image/jpeg', $preview->previewMimeType);
        self::assertSame(4096, $preview->previewOffset);
        self::assertSame(8192, $preview->previewLength);
        self::assertSame(512, $preview->thumbnailOffset);
        self::assertSame(1024, $preview->thumbnailLength);
        self::assertSame(Compression::JPEG, $preview->thumbnailCompression);
        self::assertSame(256, $preview->thumbnailTileWidth);
        self::assertSame(256, $preview->thumbnailTileLength);
        self::assertSame([512], $preview->thumbnailStripOffsets);
        self::assertSame([1024], $preview->thumbnailStripByteCounts);
        self::assertSame([512], $preview->thumbnailTileOffsets);
        self::assertSame([1024], $preview->thumbnailTileByteCounts);
        self::assertSame([4096], $preview->previewStripOffsets);
        self::assertSame([8192], $preview->previewStripByteCounts);
        self::assertSame([4096], $preview->previewTileOffsets);
        self::assertSame([8192], $preview->previewTileByteCounts);
    }
}
