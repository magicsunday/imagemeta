<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises image dimension selection logic in ParsedExif.
 * It verifies that compressed pixel dimensions override IFD0 width/length when JPEG compression is used.
 * The suite also checks the fallback to IFD0 dimensions for uncompressed images.
 * This ensures image size values match the intended EXIF precedence rules.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifImageDimensionsTest extends TestCase
{
    /**
     * Provides JPEG compression and both IFD0 and PixelX/Y dimensions.
     * Confirms the compressed pixel dimensions take precedence for image size.
     *
     * @return void
     */
    #[Test]
    public function prefersCompressedPixelDimensionsWhenAvailable(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
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

    /**
     * Uses UNCOMPRESSED compression alongside PixelX/Y dimensions.
     * Verifies the parser ignores compressed dimensions and uses IFD0 width/length.
     *
     * @return void
     */
    #[Test]
    public function ignoresCompressedDimensionsForUncompressedImages(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
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

    /**
     * Omits the Compression tag (valid for JPEG primary images per EXIF 3.0 §4.6.5.1.4).
     * Verifies that PixelX/YDimension are still used despite the defaulted UNCOMPRESSED value.
     *
     * @return void
     */
    #[Test]
    public function prefersPixelDimensionsWhenCompressionTagIsAbsent(): void
    {
        $ifd0 = new Ifd([
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
}
