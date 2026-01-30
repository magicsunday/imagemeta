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
final class ParsedExifJpegStripSuppressionTest extends TestCase
{
    /**
     * Verifies that $parsedExif->rowsPerStrip() is null.
     *
     * @return void
     */
    #[Test]
    public function suppressesStripTagsForJpegPrimaryImage(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
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
     * Verifies that $parsedExif->thumbnailStripOffsets() is null.
     *
     * @return void
     */
    #[Test]
    public function suppressesStripTagsForJpegThumbnail(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);

        $ifd1 = new Ifd([
            ExifTag::COMPRESSION       => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::STRIP_OFFSETS     => new IfdEntry(ExifTag::STRIP_OFFSETS, 4, 2, [300, 400]),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 2, [75, 80]),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, $ifd1);

        self::assertNull($parsedExif->thumbnailStripOffsets());
        self::assertNull($parsedExif->thumbnailStripByteCounts());
    }
}
