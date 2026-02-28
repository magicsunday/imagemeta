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
 * Exercises thumbnail validation logic in ParsedExif for IFD1.
 * It verifies hasThumbnail() enforces Compression=6 (JPEG) with required JPEGInterchangeFormat tags.
 * The suite confirms invalid combinations (missing tags, wrong compression) are rejected.
 * This keeps thumbnail detection consistent with EXIF 3.0 §4.6.5.1.4 and §4.6.5.1.6.
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
final class ParsedExifThumbnailValidationTest extends TestCase
{
    /**
     * Provides a valid JPEG thumbnail with Compression=6 and both required tags.
     * Verifies hasThumbnail() returns true for a properly configured IFD1.
     */
    #[Test]
    public function hasThumbnailReturnsTrueForValidJpegThumbnail(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        self::assertTrue($parsedExif->hasThumbnail());
    }

    /**
     * Sets Compression=6 but omits JPEGInterchangeFormat (tag 513).
     * Ensures hasThumbnail() returns false when the offset tag is missing.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenJpegInterchangeFormatIsMissing(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Sets Compression=6 but omits JPEGInterchangeFormatLength (tag 514).
     * Verifies hasThumbnail() returns false when the length tag is missing.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenJpegInterchangeFormatLengthIsMissing(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION             => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Provides both JPEGInterchangeFormat tags but sets Compression to UNCOMPRESSED (value 1).
     * Confirms hasThumbnail() returns false when compression is not JPEG.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenCompressionIsNotJpeg(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION => new IfdEntry(
                ExifTag::COMPRESSION,
                3,
                1,
                Compression::Uncompressed->value,
            ),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Sets up a valid JPEG thumbnail configuration but with zero length.
     * Verifies hasThumbnail() returns false for zero-length thumbnails.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenLengthIsZero(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                0,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Omits the Compression tag entirely while providing both JPEG interchange tags.
     * Ensures hasThumbnail() returns false when compression is not specified.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenCompressionTagIsMissing(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Uses LZW compression (value 5) instead of JPEG with both interchange tags present.
     * Verifies hasThumbnail() rejects non-JPEG compression schemes.
     */
    #[Test]
    public function hasThumbnailReturnsFalseForLzwCompression(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Lzw->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                2048,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Provides an empty IFD1 with no tags.
     * Confirms hasThumbnail() returns false for empty thumbnail metadata.
     */
    #[Test]
    public function hasThumbnailReturnsFalseForEmptyIfd1(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * Sets Compression=6 with valid offset but negative length.
     * Ensures hasThumbnail() returns false for invalid length values.
     */
    #[Test]
    public function hasThumbnailReturnsFalseWhenLengthIsNegative(): void
    {
        $parsedExif = $this->parsedExifWithThumbnail([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 512),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                4,
                1,
                -1,
            ),
        ]);

        self::assertFalse($parsedExif->hasThumbnail());
    }

    /**
     * @param array<int, IfdEntry> $ifd1Entries
     */
    private function parsedExifWithThumbnail(array $ifd1Entries): ParsedExif
    {
        return new ParsedExif(new Ifd([]), null, null, null, new Ifd($ifd1Entries));
    }
}
