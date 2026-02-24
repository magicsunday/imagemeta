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

/**
 * Exercises the Thumbnail value object for embedded thumbnail metadata fields.
 * It verifies offsets, lengths, and compression enums are preserved.
 * The suite covers tile and strip offsets/byte counts for different thumbnail layouts.
 * This ensures thumbnail metadata remains consistent for extraction and display.
 *
 * @internal
 */
#[CoversClass(Thumbnail::class)]
final class ThumbnailTest extends TestCase
{
    /**
     * Sets the expected boolean state for $thumbnail->hasThumbnail.
     * This checks the flag or predicate logic.
     */
    #[Test]
    public function exposesThumbnailDetails(): void
    {
        $thumbnail = new Thumbnail(
            hasThumbnail: true,
            thumbnailOffset: 512,
            thumbnailLength: 1024,
            thumbnailCompression: Compression::Jpeg,
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
        self::assertSame(Compression::Jpeg, $thumbnail->thumbnailCompression);
        self::assertSame(256, $thumbnail->thumbnailTileWidth);
        self::assertSame(256, $thumbnail->thumbnailTileLength);
        self::assertSame([512], $thumbnail->thumbnailStripOffsets);
        self::assertSame([1024], $thumbnail->thumbnailStripByteCounts);
        self::assertSame([512], $thumbnail->thumbnailTileOffsets);
        self::assertSame([1024], $thumbnail->thumbnailTileByteCounts);
    }
}
