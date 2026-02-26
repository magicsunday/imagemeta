<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\DateTimeConverter;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\FlashConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\PhotoCalculator;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Converters\SubjectAreaConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates that ConverterFactory correctly wires and exposes all converter instances.
 * It verifies the lazy circular dependency between NumericConverter and RationalConverter.
 *
 * @internal
 */
#[CoversClass(ConverterFactory::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(DateTimeConverter::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(FlashConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(PhotoCalculator::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(SubjectAreaConverter::class)]
final class ConverterFactoryTest extends TestCase
{
    private ConverterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ConverterFactory();
    }

    /**
     * Verifies all getter methods return instances of the correct types.
     */
    #[Test]
    public function exposesAllConverterInstances(): void
    {
        self::assertInstanceOf(NumericConverter::class, $this->factory->numericConverter());
        self::assertInstanceOf(RationalConverter::class, $this->factory->rationalConverter());
        self::assertInstanceOf(StringConverter::class, $this->factory->stringConverter());
        self::assertInstanceOf(DateTimeConverter::class, $this->factory->dateTimeConverter());
        self::assertInstanceOf(PhotoCalculator::class, $this->factory->photoCalculator());
        self::assertInstanceOf(SubjectAreaConverter::class, $this->factory->subjectAreaConverter());
        self::assertInstanceOf(ApexConverter::class, $this->factory->apexConverter());
        self::assertInstanceOf(FlashConverter::class, $this->factory->flashConverter());
        self::assertInstanceOf(EnumConverter::class, $this->factory->enumConverter());
        self::assertInstanceOf(MatrixConverter::class, $this->factory->matrixConverter());
        self::assertInstanceOf(ComponentsConverter::class, $this->factory->componentsConverter());
        self::assertInstanceOf(GpsUnitConverter::class, $this->factory->gpsUnitConverter());
        self::assertInstanceOf(GpsDirectionConverter::class, $this->factory->gpsDirectionConverter());
        self::assertInstanceOf(GpsConverter::class, $this->factory->gpsConverter());
    }

    /**
     * Verifies the lazy closure wiring by using the rational converter through the factory.
     * The NumericConverter's toIntList depends on RationalConverter via the lazy closure.
     */
    #[Test]
    public function circularDependencyWorksViaLazyClosure(): void
    {
        $rational = new ExifRational(6, 2);

        $result = $this->factory->rationalConverter()->toFloat($rational);

        self::assertEqualsWithDelta(3.0, $result, 0.0001);
    }

    /**
     * Returns the same instance on repeated calls.
     */
    #[Test]
    public function returnsSameInstanceOnRepeatedCalls(): void
    {
        self::assertSame(
            $this->factory->numericConverter(),
            $this->factory->numericConverter(),
        );
        self::assertSame(
            $this->factory->rationalConverter(),
            $this->factory->rationalConverter(),
        );
    }
}
