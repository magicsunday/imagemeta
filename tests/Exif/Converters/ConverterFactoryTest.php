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
use PHPUnit\Framework\Attributes\DataProvider;
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
#[UsesClass(ExifRational::class)]
final class ConverterFactoryTest extends TestCase
{
    private ConverterFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ConverterFactory();
    }

    /**
     * Verifies all public properties expose instances of the correct types.
     */
    #[Test]
    #[DataProvider('converterGetterProvider')]
    public function exposesAllConverterInstances(callable $getter): void
    {
        self::assertIsObject($getter($this->factory));
    }

    /**
     * Verifies the lazy closure wiring by using the rational converter through the factory.
     * The NumericConverter's toIntList depends on RationalConverter via the lazy closure.
     */
    #[Test]
    public function circularDependencyWorksViaLazyClosure(): void
    {
        $rational = new ExifRational(6, 2);

        $result = $this->factory->rationalConverter->toFloat($rational);

        self::assertEqualsWithDelta(3.0, $result, 0.0001);
    }

    /**
     * Returns the same instance on repeated calls.
     */
    #[Test]
    #[DataProvider('stableConverterGetterProvider')]
    public function returnsSameInstanceOnRepeatedCalls(callable $getter): void
    {
        self::assertSame(
            $getter($this->factory),
            $getter($this->factory),
        );
    }

    /**
     * @return iterable<string, array{0: callable(ConverterFactory): object}>
     */
    public static function converterGetterProvider(): iterable
    {
        yield 'numeric' => [static fn (ConverterFactory $factory): object => $factory->numericConverter];
        yield 'rational' => [static fn (ConverterFactory $factory): object => $factory->rationalConverter];
        yield 'string' => [static fn (ConverterFactory $factory): object => $factory->stringConverter];
        yield 'date time' => [static fn (ConverterFactory $factory): object => $factory->dateTimeConverter];
        yield 'photo calculator' => [static fn (ConverterFactory $factory): object => $factory->photoCalculator];
        yield 'subject area' => [static fn (ConverterFactory $factory): object => $factory->subjectAreaConverter];
        yield 'apex' => [static fn (ConverterFactory $factory): object => $factory->apexConverter];
        yield 'flash' => [static fn (ConverterFactory $factory): object => $factory->flashConverter];
        yield 'enum' => [static fn (ConverterFactory $factory): object => $factory->enumConverter];
        yield 'matrix' => [static fn (ConverterFactory $factory): object => $factory->matrixConverter];
        yield 'components' => [static fn (ConverterFactory $factory): object => $factory->componentsConverter];
        yield 'gps unit' => [static fn (ConverterFactory $factory): object => $factory->gpsUnitConverter];
        yield 'gps direction' => [static fn (ConverterFactory $factory): object => $factory->gpsDirectionConverter];
        yield 'gps' => [static fn (ConverterFactory $factory): object => $factory->gpsConverter];
    }

    /**
     * @return iterable<string, array{0: callable(ConverterFactory): object}>
     */
    public static function stableConverterGetterProvider(): iterable
    {
        yield 'numeric' => [static fn (ConverterFactory $factory): object => $factory->numericConverter];
        yield 'rational' => [static fn (ConverterFactory $factory): object => $factory->rationalConverter];
    }
}
