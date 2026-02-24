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
 * Exercises suppression of strip-related tags when JPEG compression is used.
 * It verifies rows-per-strip and strip offset/byte count fields are ignored for JPEG primaries.
 * The suite confirms JPEG interchange fields are also suppressed in this case.
 * This keeps strip metadata consistent with EXIF guidance for JPEG-compressed images.
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
final class ParsedExifJpegStripSuppressionTest extends TestCase
{
    /**
     * Sets JPEG compression on the primary image and populates strip/JPEG offset tags.
     * Verifies the parser suppresses strip-related fields for JPEG-compressed primaries.
     */
    #[Test]
    public function suppressesStripTagsForJpegPrimaryImage(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::ROWS_PER_STRIP                 => new IfdEntry(ExifTag::ROWS_PER_STRIP, 4, 1, 8),
            ExifTag::STRIP_OFFSETS                  => new IfdEntry(ExifTag::STRIP_OFFSETS, 4, 2, [100, 200]),
            ExifTag::STRIP_BYTE_COUNTS              => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 2, [50, 50]),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 1024),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->rowsPerStrip());
        self::assertNull($parsedExif->stripOffsets());
        self::assertNull($parsedExif->stripByteCounts());
        self::assertNull($parsedExif->jpegInterchangeFormat());
        self::assertNull($parsedExif->jpegInterchangeFormatLength());
    }

    /**
     * Uses JPEG compression on the thumbnail IFD while providing strip offsets/counts.
     * Ensures thumbnail strip metadata is suppressed for JPEG thumbnails.
     */
    #[Test]
    public function suppressesStripTagsForJpegThumbnail(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        $ifd1 = new Ifd([
            ExifTag::COMPRESSION       => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::STRIP_OFFSETS     => new IfdEntry(ExifTag::STRIP_OFFSETS, 4, 2, [300, 400]),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 2, [75, 80]),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, $ifd1);

        self::assertNull($parsedExif->thumbnailStripOffsets());
        self::assertNull($parsedExif->thumbnailStripByteCounts());
    }
}
