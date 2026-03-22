<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\ThumbnailExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ThumbnailExifReader for detecting JPEG thumbnails and reading
 * thumbnail-related tags from IFD1.
 *
 * @internal
 */
#[CoversClass(ThumbnailExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class ThumbnailExifReaderTest extends TestCase
{
    /**
     * Supplies IFD1 entries with JPEG thumbnail data (Compression=6, offset and length).
     * Verifies hasThumbnail() returns true and fields are populated.
     */
    #[Test]
    public function detectsJpegThumbnail(): void
    {
        $ifd1Entries = [
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 1024),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
        ];

        $reader = $this->createReader($ifd1Entries);

        self::assertTrue($reader->hasThumbnail());
        self::assertSame(Compression::Jpeg, $reader->thumbnailCompression());
        self::assertSame(1024, $reader->thumbnailJpegInterchangeFormat());
        self::assertSame(8192, $reader->thumbnailJpegInterchangeFormatLength());
    }

    /**
     * Verifies hasThumbnail() returns false when no IFD1 is present.
     */
    #[Test]
    public function returnsFalseWhenNoIfd1Present(): void
    {
        $reader = new ThumbnailExifReader(
            new IfdValueReader(new ValueConverters()),
            null,
        );

        self::assertFalse($reader->hasThumbnail());
        self::assertNull($reader->thumbnailCompression());
        self::assertNull($reader->thumbnailJpegInterchangeFormat());
        self::assertNull($reader->thumbnailJpegInterchangeFormatLength());
    }

    /**
     * Supplies IFD1 with Uncompressed compression (not JPEG).
     * Verifies hasThumbnail() returns false for non-JPEG thumbnails.
     */
    #[Test]
    public function returnsFalseForNonJpegThumbnail(): void
    {
        $ifd1Entries = [
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 1024),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
        ];

        $reader = $this->createReader($ifd1Entries);

        self::assertFalse($reader->hasThumbnail());
    }

    /**
     * Supplies IFD1 with JPEG compression but zero length.
     * Verifies hasThumbnail() returns false when length is zero.
     */
    #[Test]
    public function returnsFalseForZeroLengthThumbnail(): void
    {
        $ifd1Entries = [
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 1024),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 0),
        ];

        $reader = $this->createReader($ifd1Entries);

        self::assertFalse($reader->hasThumbnail());
    }

    /**
     * Verifies strip-based thumbnail fields are null when compression is JPEG.
     */
    #[Test]
    public function suppressesStripFieldsForJpegThumbnail(): void
    {
        $ifd1Entries = [
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
        ];

        $reader = $this->createReader($ifd1Entries);

        self::assertNull($reader->thumbnailStripOffsets());
        self::assertNull($reader->thumbnailStripByteCounts());
    }

    /**
     * @param array<int, IfdEntry> $ifd1Entries
     */
    private function createReader(array $ifd1Entries): ThumbnailExifReader
    {
        $ifd1 = new Ifd($ifd1Entries);

        return new ThumbnailExifReader(
            new IfdValueReader(new ValueConverters()),
            $ifd1,
        );
    }
}
