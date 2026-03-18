<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ImageStructureExifReader for reading image dimensions, orientation,
 * compression, resolution, and strip layout from synthetic IFD entries.
 * Verifies that defaults, fallbacks, and JPEG suppression logic work correctly.
 *
 * @internal
 */
#[CoversClass(ImageStructureExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
final class ImageStructureExifReaderTest extends TestCase
{
    /**
     * Supplies IFD0 and ExifIFD entries for image dimensions.
     * Verifies width and height are read from PixelXDimension/PixelYDimension
     * when no explicit Compression tag is present (JPEG context).
     */
    #[Test]
    public function readsImageDimensionsFromExifIfd(): void
    {
        $exifEntries = [
            ExifTag::PIXEL_X_DIMENSION => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 3, 1, 4000),
            ExifTag::PIXEL_Y_DIMENSION => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 3, 1, 3000),
        ];

        $reader      = $this->createReader([], $exifEntries);

        self::assertSame(4000, $reader->imageWidth());
        self::assertSame(3000, $reader->imageHeight());
    }

    /**
     * Supplies IFD0 entries with ImageWidth/ImageLength and Compression=Uncompressed.
     * Verifies the reader falls back to IFD0 tags when explicitly uncompressed.
     */
    #[Test]
    public function readsImageDimensionsFromIfd0WhenExplicitlyUncompressed(): void
    {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 3, 1, 5000),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, 3, 1, 4000),
            ExifTag::COMPRESSION  => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertSame(5000, $reader->imageWidth());
        self::assertSame(4000, $reader->imageHeight());
    }

    /**
     * Verifies alias methods imageLength(), pixelXDimension(), and pixelYDimension()
     * return the same values as imageHeight() and imageWidth().
     */
    #[Test]
    public function aliasMethods(): void
    {
        $exifEntries = [
            ExifTag::PIXEL_X_DIMENSION => new IfdEntry(ExifTag::PIXEL_X_DIMENSION, 3, 1, 1920),
            ExifTag::PIXEL_Y_DIMENSION => new IfdEntry(ExifTag::PIXEL_Y_DIMENSION, 3, 1, 1080),
        ];

        $reader      = $this->createReader([], $exifEntries);

        self::assertSame($reader->imageWidth(), $reader->pixelXDimension());
        self::assertSame($reader->imageHeight(), $reader->pixelYDimension());
        self::assertSame($reader->imageHeight(), $reader->imageLength());
    }

    /**
     * Verifies the default orientation is TopLeft when no Orientation tag is present.
     */
    #[Test]
    public function returnsDefaultOrientationWhenAbsent(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame(Orientation::TopLeft, $reader->orientation());
        self::assertSame('Horizontal (normal)', $reader->orientationDescription());
    }

    /**
     * Supplies an Orientation tag with value 3 (bottom-right / rotate 180).
     * Verifies the enum and description are returned correctly.
     */
    #[Test]
    public function readsOrientationFromTag(): void
    {
        $ifd0Entries = [
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::BottomRight->value),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertSame(Orientation::BottomRight, $reader->orientation());
        self::assertSame('Rotate 180', $reader->orientationDescription());
    }

    /**
     * Verifies compression defaults to Uncompressed when no Compression tag is present
     * in TIFF context (IFD0 without Compression entry).
     */
    #[Test]
    public function returnsUncompressedWhenCompressionTagAbsent(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame(Compression::Uncompressed, $reader->compression());
    }

    /**
     * Supplies a Compression tag with JPEG value.
     * Verifies the enum is returned correctly.
     */
    #[Test]
    public function readsCompressionFromTag(): void
    {
        $ifd0Entries = [
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertSame(Compression::Jpeg, $reader->compression());
    }

    /**
     * Verifies JPEG resolution defaults: 72 dpi in JPEG context (no Compression tag).
     */
    #[Test]
    public function returnsDefaultResolutionInJpegContext(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame(72.0, $reader->xResolution());
        self::assertSame(72.0, $reader->yResolution());
        self::assertSame(ResolutionUnit::Inches, $reader->resolutionUnit());
    }

    /**
     * Supplies X/Y resolution values and verifies they are read correctly.
     */
    #[Test]
    public function readsResolutionFromTags(): void
    {
        $ifd0Entries = [
            ExifTag::X_RESOLUTION => new IfdEntry(ExifTag::X_RESOLUTION, 5, 1, [300, 1]),
            ExifTag::Y_RESOLUTION => new IfdEntry(ExifTag::Y_RESOLUTION, 5, 1, [600, 1]),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertSame(300.0, $reader->xResolution());
        self::assertSame(600.0, $reader->yResolution());
    }

    /**
     * Verifies strip-related fields are null in JPEG compression context.
     */
    #[Test]
    public function suppressesStripFieldsForJpegCompression(): void
    {
        $ifd0Entries = [
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Jpeg->value),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertNull($reader->rowsPerStrip());
        self::assertNull($reader->stripOffsets());
        self::assertNull($reader->stripByteCounts());
        self::assertNull($reader->jpegInterchangeFormat());
        self::assertNull($reader->jpegInterchangeFormatLength());
    }

    /**
     * Verifies null dimensions when no dimension tags are present.
     */
    #[Test]
    public function returnsNullDimensionsWhenAbsent(): void
    {
        $ifd0Entries = [
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ];

        $reader      = $this->createReader($ifd0Entries, []);

        self::assertNull($reader->imageWidth());
        self::assertNull($reader->imageHeight());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $ifd0Entries, array $exifEntries): ImageStructureExifReader
    {
        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new ImageStructureExifReader(
            new IfdValueReader(new ValueConverters()),
            $ifd0,
            $exifIfd,
        );
    }
}
