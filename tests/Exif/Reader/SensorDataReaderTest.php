<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

use MagicSunday\ImageMeta\Core\Endian;
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
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\SensorDataReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SensorDataReader for reading composite image, OECF, and spatial frequency
 * response metadata from synthetic IFD entries.
 *
 * @internal
 */
#[CoversClass(SensorDataReader::class)]
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
#[UsesClass(ExifNumericList::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class SensorDataReaderTest extends TestCase
{
    /**
     * Supplies a CompositeImage tag with value 2 (general composite).
     * Verifies the enum is returned correctly.
     */
    #[Test]
    public function readsCompositeImage(): void
    {
        $exifEntries = [
            ExifTag::COMPOSITE_IMAGE => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GeneralComposite->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(CompositeImage::GeneralComposite, $reader->compositeImage());
    }

    /**
     * Verifies null is returned when no CompositeImage tag is present.
     */
    #[Test]
    public function returnsNullWhenNoCompositeImagePresent(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->compositeImage());
    }

    /**
     * Supplies a SourceImageNumberOfCompositeImage tag with two valid SHORT values.
     * Verifies the captured and used counts are returned correctly.
     */
    #[Test]
    public function readsSourceImageNumberOfCompositeImage(): void
    {
        $exifEntries = [
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                3,
                2,
                [5, 3],
            ),
        ];

        $reader = $this->createReader($exifEntries);

        $result = $reader->sourceImageNumberOfCompositeImage();
        self::assertSame([5, 3], $result);
    }

    /**
     * Supplies a SourceImageNumberOfCompositeImage tag where the used count
     * exceeds the captured count. Verifies null is returned for invalid data.
     */
    #[Test]
    public function returnsNullForInvalidSourceImageCount(): void
    {
        $exifEntries = [
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                3,
                2,
                [3, 5],
            ),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertNull($reader->sourceImageNumberOfCompositeImage());
    }

    /**
     * Verifies that absent OECF and SpatialFrequencyResponse return null.
     */
    #[Test]
    public function returnsNullForAbsentSensorPayloads(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->oecf());
        self::assertNull($reader->oecfPayload());
        self::assertNull($reader->sourceExposureTimesOfCompositeImage());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $exifEntries): SensorDataReader
    {
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new SensorDataReader(
            new IfdValueReader(new ValueConverters()),
            new ValueConverters(),
            $exifIfd,
            Endian::Little,
        );
    }
}
