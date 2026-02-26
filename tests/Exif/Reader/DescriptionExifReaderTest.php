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
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DescriptionExifReader for reading software, document name, copyright,
 * artist, image description, EXIF version, and FlashPix version metadata.
 *
 * @internal
 */
#[CoversClass(DescriptionExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
final class DescriptionExifReaderTest extends TestCase
{
    /**
     * Supplies IFD0 and ExifIFD entries for document-related metadata.
     * Verifies software, copyright, artist, and image description are read correctly.
     */
    #[Test]
    public function readsDocumentMetadata(): void
    {
        $ifd0Entries = [
            ExifTag::SOFTWARE          => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'Adobe Lightroom 6.0'),
            ExifTag::COPYRIGHT         => new IfdEntry(ExifTag::COPYRIGHT, 2, 1, '2024 John Doe'),
            ExifTag::ARTIST            => new IfdEntry(ExifTag::ARTIST, 2, 1, 'Jane Smith'),
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 1, 'A sunset over the ocean'),
        ];

        $reader = $this->createReader($ifd0Entries, []);

        self::assertSame('Adobe Lightroom 6.0', $reader->software());
        self::assertSame('2024 John Doe', $reader->copyright());
        self::assertSame('Jane Smith', $reader->artist());
        self::assertSame('A sunset over the ocean', $reader->imageDescription());
    }

    /**
     * Supplies an ExifVersion raw string "0232" in the ExifIFD.
     * Verifies the version is normalized to "2.32".
     */
    #[Test]
    public function readsExifVersion(): void
    {
        $exifEntries = [
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0232'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame('2.32', $reader->exifVersion());
    }

    /**
     * Verifies FlashPix version defaults to 1.00 when the tag is absent.
     */
    #[Test]
    public function returnsDefaultFlashpixVersionWhenAbsent(): void
    {
        $reader = $this->createReader([], []);

        self::assertSame('1.00', $reader->flashpixVersion());
    }

    /**
     * Supplies a FlashPix version tag with value "0100".
     */
    #[Test]
    public function readsFlashpixVersion(): void
    {
        $exifEntries = [
            ExifTag::FLASHPIX_VERSION => new IfdEntry(ExifTag::FLASHPIX_VERSION, 7, 4, '0100'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame('1.00', $reader->flashpixVersion());
    }

    /**
     * Supplies an ImageUniqueID tag with a valid 32-character hex string.
     * Verifies the ID is returned as-is.
     */
    #[Test]
    public function readsImageUniqueId(): void
    {
        $exifEntries = [
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(ExifTag::IMAGE_UNIQUE_ID, 2, 33, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $reader->imageUniqueId());
    }

    /**
     * Supplies an invalid ImageUniqueID (not 32 hex chars).
     * Verifies the reader returns null for non-conformant values.
     */
    #[Test]
    public function returnsNullForInvalidImageUniqueId(): void
    {
        $exifEntries = [
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(ExifTag::IMAGE_UNIQUE_ID, 2, 10, 'not-hex-id'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertNull($reader->imageUniqueId());
    }

    /**
     * Verifies null is returned for all fields when no entries are present.
     */
    #[Test]
    public function returnsNullWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([], []);

        self::assertNull($reader->software());
        self::assertNull($reader->copyright());
        self::assertNull($reader->imageDescription());
        self::assertNull($reader->imageUniqueId());
        self::assertNull($reader->exifVersion());
        self::assertNull($reader->documentName());
        self::assertNull($reader->photographer());
        self::assertNull($reader->imageEditor());
        self::assertNull($reader->imageTitle());
    }

    /**
     * Verifies artist() falls back to CameraOwnerName when Artist tag is absent.
     */
    #[Test]
    public function artistFallsBackToCameraOwnerName(): void
    {
        $exifEntries = [
            ExifTag::CAMERA_OWNER_NAME => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, 'Fallback Owner'),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertSame('Fallback Owner', $reader->artist());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $ifd0Entries, array $exifEntries): DescriptionExifReader
    {
        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new DescriptionExifReader(
            new IfdValueReader(new ValueConverters()),
            new ValueConverters(),
            $ifd0,
            $exifIfd,
        );
    }
}
