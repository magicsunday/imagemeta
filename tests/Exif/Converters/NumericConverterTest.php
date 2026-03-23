<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates numeric EXIF value normalization into integer lists.
 * It verifies handling of integers, floats, strings, UInt64, ExifNumericList, and rational types.
 *
 * @internal
 */
#[CoversClass(NumericConverter::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifRational::class)]
final class NumericConverterTest extends TestCase
{
    private NumericConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new NumericConverter();
    }

    /**
     * Normalizes an integer component to float.
     */
    #[Test]
    public function normalizeComponentReturnsFloatForInt(): void
    {
        self::assertSame(42.0, $this->converter->normalizeComponent(42));
    }

    /**
     * Normalizes a float component.
     */
    #[Test]
    public function normalizeComponentReturnsFloatForFloat(): void
    {
        self::assertSame(3.14, $this->converter->normalizeComponent(3.14));
    }

    /**
     * Normalizes a numeric string component.
     */
    #[Test]
    public function normalizeComponentReturnsFloatForNumericString(): void
    {
        self::assertSame(7.0, $this->converter->normalizeComponent('7'));
    }

    /**
     * Returns null for non-numeric string component.
     */
    #[Test]
    public function normalizeComponentReturnsNullForNonNumericString(): void
    {
        self::assertNull($this->converter->normalizeComponent('abc'));
    }

    /**
     * Normalizes a UInt64 that fits into signed int range.
     */
    #[Test]
    public function normalizeComponentReturnsFloatForUInt64(): void
    {
        self::assertSame(100.0, $this->converter->normalizeComponent(UInt64::fromInt(100)));
    }

    /**
     * Converts a UInt64 to int.
     */
    #[Test]
    public function uint64ToIntConvertsValidValue(): void
    {
        self::assertSame(42, $this->converter->uint64ToInt(UInt64::fromInt(42), 'test'));
    }

    /**
     * Converts a single integer to a one-element int list.
     */
    #[Test]
    public function toIntListConvertsInteger(): void
    {
        self::assertSame([42], $this->converter->toIntList(42));
    }

    /**
     * Converts a whole float to a one-element int list.
     */
    #[Test]
    public function toIntListConvertsWholeFloat(): void
    {
        self::assertSame([3], $this->converter->toIntList(3.0));
    }

    /**
     * Returns null for a fractional float.
     */
    #[Test]
    public function toIntListReturnsNullForFractionalFloat(): void
    {
        self::assertNull($this->converter->toIntList(3.5));
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function toIntListReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toIntList(null));
    }

    /**
     * Returns null for empty string.
     */
    #[Test]
    public function toIntListReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->toIntList(''));
    }

    /**
     * Converts a binary string to byte value list via ord().
     */
    #[Test]
    public function toIntListConvertsStringToByteValues(): void
    {
        self::assertSame([65, 66], $this->converter->toIntList('AB'));
    }

    /**
     * Converts an ExifNumericList to int list.
     */
    #[Test]
    public function toIntListConvertsExifNumericList(): void
    {
        self::assertSame([1, 2, 3], $this->converter->toIntList(new ExifNumericList([1, 2, 3])));
    }

    /**
     * Returns null for an empty ExifNumericList.
     */
    #[Test]
    public function toIntListReturnsNullForEmptyNumericList(): void
    {
        self::assertNull($this->converter->toIntList(new ExifNumericList([])));
    }

    /**
     * Converts a UInt64 value to a one-element int list.
     */
    #[Test]
    public function toIntListConvertsUInt64(): void
    {
        self::assertSame([99], $this->converter->toIntList(UInt64::fromInt(99)));
    }

    /**
     * Converts an array of integers to int list.
     */
    #[Test]
    public function toIntListConvertsArray(): void
    {
        self::assertSame([10, 20, 30], $this->converter->toIntList([10, 20, 30]));
    }

    /**
     * Returns null for empty array input.
     */
    #[Test]
    public function toIntListReturnsNullForEmptyArray(): void
    {
        self::assertNull($this->converter->toIntList([]));
    }

    /**
     * Returns null for array containing non-numeric values.
     */
    #[Test]
    public function toIntListReturnsNullForArrayWithNonNumeric(): void
    {
        self::assertNull($this->converter->toIntList(['not', 'numeric']));
    }

    /**
     * Returns null for ExifRational without a rational-to-float callback.
     */
    #[Test]
    public function toIntListReturnsNullForRationalWithoutCallback(): void
    {
        self::assertNull($this->converter->toIntList(new ExifRational(1, 1)));
    }

    /**
     * Returns null for empty ExifRationalList without a callback.
     */
    #[Test]
    public function toIntListReturnsNullForEmptyRationalList(): void
    {
        self::assertNull($this->converter->toIntList(new ExifRationalList([])));
    }

    /**
     * Returns null for ExifRationalList without a rational-to-float callback.
     */
    #[Test]
    public function toIntListReturnsNullForRationalListWithoutCallback(): void
    {
        self::assertNull($this->converter->toIntList(
            new ExifRationalList([new ExifRational(1, 1)]),
        ));
    }

    /**
     * Converts an ExifRational when a rational-to-float callback is provided.
     */
    #[Test]
    public function toIntListConvertsRationalWithCallback(): void
    {
        $converter = new NumericConverter(
            static fn (mixed $value): ?float => ($value instanceof ExifRational) && ($value->denominator !== 0)
                ? $value->numerator / $value->denominator
                : null,
        );

        self::assertSame([3], $converter->toIntList(new ExifRational(6, 2)));
    }

    /**
     * Returns null for ExifRational that produces a fractional result via callback.
     */
    #[Test]
    public function toIntListReturnsNullForFractionalRationalWithCallback(): void
    {
        $converter = new NumericConverter(
            static fn (mixed $value): ?float => ($value instanceof ExifRational) && ($value->denominator !== 0)
                ? $value->numerator / $value->denominator
                : null,
        );

        self::assertNull($converter->toIntList(new ExifRational(1, 3)));
    }

    /**
     * Converts an ExifNumericList containing a UInt64 value.
     */
    #[Test]
    public function toIntListConvertsNumericListWithUInt64(): void
    {
        self::assertSame([5], $this->converter->toIntList(new ExifNumericList([UInt64::fromInt(5)])));
    }

    /**
     * Handles array containing UInt64 values.
     */
    #[Test]
    public function toIntListConvertsArrayWithUInt64(): void
    {
        self::assertSame([7], $this->converter->toIntList([UInt64::fromInt(7)]));
    }
}
