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
use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\DeviceExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DeviceExifReader for environmental sensor data and unknown-denominator handling.
 *
 * @internal
 */
#[CoversClass(DeviceExifReader::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
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
final class DeviceExifReaderTest extends TestCase
{
    /**
     * Supplies an Acceleration tag with three valid SRATIONAL components.
     * Verifies the acceleration vector is returned and scaled from mGal to m/s².
     */
    #[Test]
    public function returnsAccelerationVectorForValidComponents(): void
    {
        $reader = $this->createReader([
            ExifTag::ACCELERATION => new IfdEntry(ExifTag::ACCELERATION, 10, 3, [
                [10000, 1],
                [20000, 1],
                [30000, 1],
            ]),
        ]);

        $vector = $reader->accelerationVector();

        self::assertNotNull($vector);
        self::assertEqualsWithDelta(0.1, $vector[0], 0.00001);
        self::assertEqualsWithDelta(0.2, $vector[1], 0.00001);
        self::assertEqualsWithDelta(0.3, $vector[2], 0.00001);
    }

    /**
     * Supplies an Acceleration tag where one component has the EXIF unknown denominator
     * sentinel (0xFFFFFFFF). Verifies null is returned when any component in the
     * ExifRationalList is unknown.
     */
    #[Test]
    public function returnsNullForAccelerationWithUnknownDenominatorInList(): void
    {
        $reader = $this->createReader([
            ExifTag::ACCELERATION => new IfdEntry(ExifTag::ACCELERATION, 10, 3, [
                [10000, 1],
                [0, ExifConst::EXIF_UNKNOWN_DENOMINATOR],
                [30000, 1],
            ]),
        ]);

        self::assertNull($reader->accelerationVector());
    }

    /**
     * Verifies null is returned when no acceleration entries are present.
     */
    #[Test]
    public function returnsNullWhenNoAccelerationPresent(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->accelerationVector());
        self::assertNull($reader->accelerationMs2());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $exifEntries): DeviceExifReader
    {
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new DeviceExifReader(
            new IfdValueReader(new ValueConverters()),
            new ValueConverters(),
            null,
            $exifIfd,
            Endian::Little,
        );
    }
}
