<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers TIFF 6.0 legacy JPEG field accessors exposed by ParsedExif.
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifJpegLegacyTagsTest extends TestCase
{
    /**
     * Returns the JPEGLosslessPredictors (0x0205) value when present in IFD0.
     */
    #[Test]
    public function jpegLosslessPredictorsReturnsIfd0Value(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            TiffTag::JPEG_LOSSLESS_PREDICTORS => new IfdEntry(
                TiffTag::JPEG_LOSSLESS_PREDICTORS,
                3,
                3,
                [1, 3, 5],
            ),
        ]);

        $value      = $parsedExif->jpegLosslessPredictors();

        self::assertInstanceOf(ExifNumericList::class, $value);
        self::assertSame([1, 3, 5], $value->values);
    }

    /**
     * Returns null for JPEGLosslessPredictors when the tag is missing.
     */
    #[Test]
    public function jpegLosslessPredictorsReturnsNullWhenAbsent(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->jpegLosslessPredictors());
    }

    /**
     * Keeps neighboring TIFF 6.0 JPEG legacy accessors unchanged.
     */
    #[Test]
    public function neighboringJpegLegacyAccessorsRemainUnchanged(): void
    {
        $parsedExif             = $this->parsedExifFromIfd0([
            TiffTag::JPEG_PROC                => new IfdEntry(TiffTag::JPEG_PROC, 3, 1, 14),
            TiffTag::JPEG_RESTART_INTERVAL    => new IfdEntry(TiffTag::JPEG_RESTART_INTERVAL, 3, 1, 8),
            TiffTag::JPEG_LOSSLESS_PREDICTORS => new IfdEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 3, 2, [4, 6]),
            TiffTag::JPEG_POINT_TRANSFORMS    => new IfdEntry(TiffTag::JPEG_POINT_TRANSFORMS, 3, 2, [0, 1]),
            TiffTag::JPEG_Q_TABLES            => new IfdEntry(TiffTag::JPEG_Q_TABLES, 4, 2, [100, 200]),
            TiffTag::JPEG_DC_TABLES           => new IfdEntry(TiffTag::JPEG_DC_TABLES, 4, 2, [300, 400]),
            TiffTag::JPEG_AC_TABLES           => new IfdEntry(TiffTag::JPEG_AC_TABLES, 4, 2, [500, 600]),
        ]);

        $jpegLosslessPredictors = $parsedExif->jpegLosslessPredictors();
        $jpegPointTransforms    = $parsedExif->jpegPointTransforms();
        $jpegQTables            = $parsedExif->jpegQTables();
        $jpegDCTables           = $parsedExif->jpegDCTables();
        $jpegACTables           = $parsedExif->jpegACTables();

        self::assertSame(14, $parsedExif->jpegProc());
        self::assertSame(8, $parsedExif->jpegRestartInterval());
        self::assertInstanceOf(ExifNumericList::class, $jpegLosslessPredictors);
        self::assertSame([4, 6], $jpegLosslessPredictors->values);
        self::assertInstanceOf(ExifNumericList::class, $jpegPointTransforms);
        self::assertSame([0, 1], $jpegPointTransforms->values);
        self::assertInstanceOf(ExifNumericList::class, $jpegQTables);
        self::assertSame([100, 200], $jpegQTables->values);
        self::assertInstanceOf(ExifNumericList::class, $jpegDCTables);
        self::assertSame([300, 400], $jpegDCTables->values);
        self::assertInstanceOf(ExifNumericList::class, $jpegACTables);
        self::assertSame([500, 600], $jpegACTables->values);
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     */
    private function parsedExifFromIfd0(array $ifd0Entries): ParsedExif
    {
        return new ParsedExif(new Ifd($ifd0Entries), null, null, null, null);
    }
}
