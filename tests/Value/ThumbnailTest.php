<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Thumbnail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Thumbnail::class)]
final class ThumbnailTest extends TestCase
{
    #[Test]
    public function exposesThumbnailDetails(): void
    {
        $thumbnail = new Thumbnail(
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
        );

        self::assertTrue($thumbnail->hasThumbnail);
        self::assertSame(512, $thumbnail->thumbnailOffset);
        self::assertSame(1024, $thumbnail->thumbnailLength);
        self::assertSame(Compression::JPEG, $thumbnail->thumbnailCompression);
        self::assertSame(256, $thumbnail->thumbnailTileWidth);
        self::assertSame(256, $thumbnail->thumbnailTileLength);
        self::assertSame([512], $thumbnail->thumbnailStripOffsets);
        self::assertSame([1024], $thumbnail->thumbnailStripByteCounts);
        self::assertSame([512], $thumbnail->thumbnailTileOffsets);
        self::assertSame([1024], $thumbnail->thumbnailTileByteCounts);
    }
}
