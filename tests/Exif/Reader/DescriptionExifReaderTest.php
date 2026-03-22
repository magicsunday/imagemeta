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
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\LearningIntention;
use MagicSunday\ImageMeta\Value\Enum\LearningUsage;
use MagicSunday\ImageMeta\Value\LearningOptOutIn;
use MagicSunday\ImageMeta\Value\LearningOptOutInEntry;
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
#[UsesClass(LearningIntention::class)]
#[UsesClass(LearningOptOutIn::class)]
#[UsesClass(LearningOptOutInEntry::class)]
#[UsesClass(LearningUsage::class)]
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
#[UsesClass(StringConverter::class)]
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
     * Supplies a single-pair LearningOptOutIn payload: Usage=All, Intention=Opt-out.
     * Verifies the reader parses a single (usage, intention) byte pair.
     */
    #[Test]
    public function readsLearningOptOutInSinglePair(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(ExifTag::LEARNING_OPT_OUT_IN, 7, 2, "\x00\x00"),
        ];

        $reader = $this->createReader([], $exifEntries);
        $result = $reader->learningOptOutIn();

        self::assertNotNull($result);
        self::assertCount(1, $result->entries);
        self::assertSame(LearningUsage::All, $result->entries[0]->usage);
        self::assertSame(LearningIntention::OptOut, $result->entries[0]->intention);
    }

    /**
     * Supplies a multi-pair LearningOptOutIn payload:
     * All/Opt-out + NonGenerative/Opt-in.
     */
    #[Test]
    public function readsLearningOptOutInMultiPair(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(ExifTag::LEARNING_OPT_OUT_IN, 7, 4, "\x00\x00\x01\x01"),
        ];

        $reader = $this->createReader([], $exifEntries);
        $result = $reader->learningOptOutIn();

        self::assertNotNull($result);
        self::assertCount(2, $result->entries);
        self::assertSame(LearningUsage::All, $result->entries[0]->usage);
        self::assertSame(LearningIntention::OptOut, $result->entries[0]->intention);
        self::assertSame(LearningUsage::NonGenerativeTraining, $result->entries[1]->usage);
        self::assertSame(LearningIntention::OptIn, $result->entries[1]->intention);
    }

    /**
     * Supplies all five usage values paired with different intentions.
     */
    #[Test]
    public function readsAllLearningUsageValues(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(
                ExifTag::LEARNING_OPT_OUT_IN,
                7,
                10,
                "\x00\x02\x01\x01\x02\x00\x03\x00\x04\x01",
            ),
        ];

        $reader = $this->createReader([], $exifEntries);
        $result = $reader->learningOptOutIn();

        self::assertNotNull($result);
        self::assertCount(5, $result->entries);
        self::assertSame(LearningUsage::All, $result->entries[0]->usage);
        self::assertSame(LearningIntention::Unspecified, $result->entries[0]->intention);
        self::assertSame(LearningUsage::NonGenerativeTraining, $result->entries[1]->usage);
        self::assertSame(LearningUsage::GenerativeTraining, $result->entries[2]->usage);
        self::assertSame(LearningUsage::DataMining, $result->entries[3]->usage);
        self::assertSame(LearningUsage::FoundationModelInput, $result->entries[4]->usage);
    }

    /**
     * Verifies null is returned when the tag is absent.
     */
    #[Test]
    public function returnsNullForAbsentLearningOptOutIn(): void
    {
        $reader = $this->createReader([], []);

        self::assertNull($reader->learningOptOutIn());
    }

    /**
     * Supplies an empty UNDEFINED payload (zero bytes).
     * Verifies null is returned for payloads too short to contain a pair.
     */
    #[Test]
    public function returnsNullForEmptyLearningOptOutInPayload(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(ExifTag::LEARNING_OPT_OUT_IN, 7, 0, ''),
        ];

        $reader = $this->createReader([], $exifEntries);

        self::assertNull($reader->learningOptOutIn());
    }

    /**
     * Supplies an odd-length payload (3 bytes). The trailing incomplete byte is ignored.
     */
    #[Test]
    public function ignoresTrailingByteInOddLengthPayload(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(ExifTag::LEARNING_OPT_OUT_IN, 7, 3, "\x00\x00\x01"),
        ];

        $reader = $this->createReader([], $exifEntries);
        $result = $reader->learningOptOutIn();

        self::assertNotNull($result);
        self::assertCount(1, $result->entries);
    }

    /**
     * Supplies a pair with reserved usage and intention byte values.
     * Pairs with unknown enum values are skipped.
     */
    #[Test]
    public function skipsUnknownUsageOrIntentionValues(): void
    {
        $exifEntries = [
            ExifTag::LEARNING_OPT_OUT_IN => new IfdEntry(
                ExifTag::LEARNING_OPT_OUT_IN,
                7,
                6,
                "\x00\x00\xFF\x00\x01\xFF",
            ),
        ];

        $reader = $this->createReader([], $exifEntries);
        $result = $reader->learningOptOutIn();

        self::assertNotNull($result);
        self::assertCount(1, $result->entries);
        self::assertSame(LearningUsage::All, $result->entries[0]->usage);
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
