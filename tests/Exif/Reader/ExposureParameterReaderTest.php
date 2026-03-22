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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\ExposureParameterReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExposureParameterReader for reading exposure time, aperture, program,
 * shutter speed, brightness, and exposure bias from synthetic IFD entries.
 *
 * @internal
 */
#[CoversClass(ExposureParameterReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ExifRational::class)]
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
final class ExposureParameterReaderTest extends TestCase
{
    /**
     * Supplies ExifIFD entries with exposure time and f-number.
     * Verifies both scalar values are read correctly as floats.
     */
    #[Test]
    public function readsExposureTimeAndFNumber(): void
    {
        $exifEntries = [
            ExifTag::EXPOSURE_TIME => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [1, 200]),
            ExifTag::F_NUMBER      => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [28, 10]),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertEqualsWithDelta(1.0 / 200.0, $reader->exposureTime(), 0.00001);
        self::assertEqualsWithDelta(2.8, $reader->fNumber(), 0.001);
    }

    /**
     * Supplies an ExposureProgram tag with value 2 (Normal program).
     * Verifies the enum is returned correctly.
     */
    #[Test]
    public function readsExposureProgram(): void
    {
        $exifEntries = [
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, ExposureProgram::Normal->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(ExposureProgram::Normal, $reader->exposureProgram());
    }

    /**
     * Verifies ExposureProgram defaults to NotDefined when the tag is absent.
     */
    #[Test]
    public function returnsDefaultExposureProgramWhenAbsent(): void
    {
        $reader = $this->createReader([]);

        self::assertSame(ExposureProgram::NotDefined, $reader->exposureProgram());
    }

    /**
     * Supplies an ExposureMode tag with value 1 (Manual exposure).
     * Verifies the enum is returned correctly.
     */
    #[Test]
    public function readsExposureMode(): void
    {
        $exifEntries = [
            ExifTag::EXPOSURE_MODE => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, ExposureMode::Manual->value),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertSame(ExposureMode::Manual, $reader->exposureMode());
    }

    /**
     * Supplies an ExposureBiasValue rational tag.
     * Verifies the bias is read as a float.
     */
    #[Test]
    public function readsExposureBias(): void
    {
        $exifEntries = [
            ExifTag::EXPOSURE_BIAS_VALUE => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, [-1, 3]),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertEqualsWithDelta(-1.0 / 3.0, $reader->exposureBias(), 0.001);
    }

    /**
     * Verifies all fields return null when no EXIF entries are present.
     */
    #[Test]
    public function returnsNullWhenNoEntriesPresent(): void
    {
        $reader = $this->createReader([]);

        self::assertNull($reader->exposureTime());
        self::assertNull($reader->fNumber());
        self::assertNull($reader->exposureMode());
        self::assertNull($reader->exposureBias());
        self::assertNull($reader->maxApertureApex());
        self::assertNull($reader->shutterSpeedValue());
        self::assertNull($reader->apertureValue());
        self::assertNull($reader->brightnessValue());
        self::assertNull($reader->digitalZoomRatio());
        self::assertNull($reader->exposureIndex());
    }

    /**
     * Supplies a DigitalZoomRatio of 0/1 (no digital zoom).
     * Verifies the reader returns null for zero ratio.
     */
    #[Test]
    public function returnsNullForZeroDigitalZoomRatio(): void
    {
        $exifEntries = [
            ExifTag::DIGITAL_ZOOM_RATIO => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [0, 1]),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertNull($reader->digitalZoomRatio());
    }

    /**
     * Supplies a BrightnessValue with numerator -1 (unknown sentinel).
     * Verifies the reader returns null for unknown brightness.
     */
    #[Test]
    public function returnsNullForUnknownBrightness(): void
    {
        $exifEntries = [
            ExifTag::BRIGHTNESS_VALUE => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, [-1, 1]),
        ];

        $reader = $this->createReader($exifEntries);

        self::assertNull($reader->brightnessValue());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createReader(array $exifEntries): ExposureParameterReader
    {
        $exifIfd = $exifEntries !== [] ? new Ifd($exifEntries) : null;

        return new ExposureParameterReader(
            new IfdValueReader(new ValueConverters()),
            new ValueConverters(),
            $exifIfd,
        );
    }
}
