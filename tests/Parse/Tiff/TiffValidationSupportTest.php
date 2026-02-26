<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiffValidationSupport::class)]
final class TiffValidationSupportTest extends TestCase
{
    #[Test]
    public function countedImageDataTagNameResolvesKnownStripAndTileTags(): void
    {
        self::assertSame('StripOffsets', TiffValidationSupport::countedImageDataTagName(ExifTag::STRIP_OFFSETS));
        self::assertSame('StripByteCounts', TiffValidationSupport::countedImageDataTagName(ExifTag::STRIP_BYTE_COUNTS));
        self::assertSame('TileOffsets', TiffValidationSupport::countedImageDataTagName(TiffTag::TILE_OFFSETS));
        self::assertSame('TileByteCounts', TiffValidationSupport::countedImageDataTagName(TiffTag::TILE_BYTE_COUNTS));
    }

    #[Test]
    public function countedImageDataTagNameFallsBackToHexTagLabel(): void
    {
        self::assertSame('IFD tag 0x1234', TiffValidationSupport::countedImageDataTagName(0x1234));
    }
}
