<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsDirectionConverter::normalizeBearing() with valid, invalid
 * and edge-case compass bearing values per EXIF 3.0 §4.6.7.1.16/§4.6.7.1.18/§4.6.7.1.25.
 *
 * @internal
 */
#[CoversClass(GpsDirectionConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ParseError::class)]
#[UsesTrait(ValidatesGpsRef::class)]
final class GpsDirectionConverterTest extends TestCase
{
    private GpsDirectionConverter $converter;

    protected function setUp(): void
    {
        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);

        $this->converter = new GpsDirectionConverter($rationalConverter);
    }

    /**
     * Verifies that valid bearing values are accepted and returned as floats.
     */
    #[Test]
    #[DataProvider('provideValidBearings')]
    public function acceptsValidBearings(int|float|null $input, ?float $expected): void
    {
        self::assertSame($expected, $this->converter->normalizeBearing($input));
    }

    /**
     * @return iterable<string, array{0: int|float|null, 1: ?float}>
     */
    public static function provideValidBearings(): iterable
    {
        yield 'zero bearing' => [0.0, 0.0];
        yield 'mid bearing' => [180.0, 180.0];
        yield 'max valid float' => [359.99, 359.99];
        yield 'null passthrough' => [null, null];
    }

    /**
     * Tolerates out-of-range bearings by normalizing via modular arithmetic.
     */
    #[Test]
    #[DataProvider('provideOutOfRangeBearings')]
    public function toleratesOutOfRangeBearings(int|float $input, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $this->converter->normalizeBearing($input), 0.000001);
    }

    /**
     * @return iterable<string, array{0: int|float, 1: float}>
     */
    public static function provideOutOfRangeBearings(): iterable
    {
        yield 'just below zero' => [-0.1, 359.9];
        yield 'exactly 360' => [360.0, 0.0];
        yield 'above 360' => [361.0, 1.0];
        yield 'negative integer' => [-1.0, 359.0];
    }

    /**
     * Verifies boundary values at the edges of the valid [0, 360) range.
     */
    #[Test]
    #[DataProvider('provideBearingEdgeCases')]
    public function acceptsBearingEdgeCases(int|float $input, float $expected): void
    {
        self::assertSame($expected, $this->converter->normalizeBearing($input));
    }

    /**
     * @return iterable<string, array{0: int|float, 1: float}>
     */
    public static function provideBearingEdgeCases(): iterable
    {
        yield 'lower bound exactly zero' => [0.0, 0.0];
        yield 'upper bound just below 360' => [359.999, 359.999];
    }
}
