<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifImageDimensionsTest extends TestCase
{
    #[Test]
    public function prefersCompressedPixelDimensionsWhenAvailable(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, 4, 1, 480),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PIXEL_X_DIMENSION => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 4, 1, 800),
            ExifTag::PIXEL_Y_DIMENSION => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 4, 1, 600),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(800, $parsedExif->imageWidth());
        self::assertSame(600, $parsedExif->imageHeight());
    }

    #[Test]
    public function ignoresCompressedDimensionsForUncompressedImages(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, 4, 1, 768),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PIXEL_X_DIMENSION => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 4, 1, 800),
            ExifTag::PIXEL_Y_DIMENSION => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 4, 1, 600),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(1024, $parsedExif->imageWidth());
        self::assertSame(768, $parsedExif->imageHeight());
    }
}
