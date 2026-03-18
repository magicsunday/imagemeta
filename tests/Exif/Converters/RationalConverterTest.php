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
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates TIFF/EXIF RATIONAL and SRATIONAL conversion to PHP floats.
 * It verifies handling of ExifRational, ExifRationalList, nested arrays, scalars, and UInt64.
 *
 * @internal
 */
#[CoversClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRationalList::class)]
final class RationalConverterTest extends TestCase
{
    private RationalConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new RationalConverter(new NumericConverter());
    }

    /**
     * Converts an ExifRational to float.
     */
    #[Test]
    public function toFloatConvertsRational(): void
    {
        self::assertEqualsWithDelta(2.5, $this->converter->toFloat(new ExifRational(5, 2)), 0.0001);
    }

    /**
     * Returns null for ExifRational with zero denominator.
     */
    #[Test]
    public function toFloatReturnsNullForZeroDenominator(): void
    {
        self::assertNull($this->converter->toFloat(new ExifRational(1, 0)));
    }

    /**
     * Converts an integer to float.
     */
    #[Test]
    public function toFloatConvertsInteger(): void
    {
        self::assertSame(42.0, $this->converter->toFloat(42));
    }

    /**
     * Converts a float to float (pass-through).
     */
    #[Test]
    public function toFloatConvertsFloat(): void
    {
        self::assertSame(3.14, $this->converter->toFloat(3.14));
    }

    /**
     * Converts a numeric string to float.
     */
    #[Test]
    public function toFloatConvertsNumericString(): void
    {
        self::assertSame(7.5, $this->converter->toFloat('7.5'));
    }

    /**
     * Returns null for non-numeric string.
     */
    #[Test]
    public function toFloatReturnsNullForNonNumericString(): void
    {
        self::assertNull($this->converter->toFloat('not-a-number'));
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function toFloatReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toFloat(null));
    }

    /**
     * Converts a UInt64 value to float.
     */
    #[Test]
    public function toFloatConvertsUInt64(): void
    {
        self::assertSame(100.0, $this->converter->toFloat(UInt64::fromInt(100)));
    }

    /**
     * Extracts the first rational from an ExifRationalList.
     */
    #[Test]
    public function toFloatExtractsFirstFromRationalList(): void
    {
        $list = new ExifRationalList([
            new ExifRational(3, 1),
            new ExifRational(7, 1),
        ]);

        self::assertEqualsWithDelta(3.0, $this->converter->toFloat($list), 0.0001);
    }

    /**
     * Returns null for empty ExifRationalList.
     */
    #[Test]
    public function toFloatReturnsNullForEmptyRationalList(): void
    {
        self::assertNull($this->converter->toFloat(new ExifRationalList([])));
    }

    /**
     * Extracts the first value from an ExifNumericList.
     */
    #[Test]
    public function toFloatExtractsFirstFromNumericList(): void
    {
        self::assertSame(5.0, $this->converter->toFloat(new ExifNumericList([5])));
    }

    /**
     * Returns null for empty ExifNumericList.
     */
    #[Test]
    public function toFloatReturnsNullForEmptyNumericList(): void
    {
        self::assertNull($this->converter->toFloat(new ExifNumericList([])));
    }

    /**
     * Converts a nested array [[1, 2], [3, 4]] extracting the first rational pair.
     */
    #[Test]
    public function toFloatConvertsNestedArray(): void
    {
        self::assertEqualsWithDelta(0.5, $this->converter->toFloat([[1, 2], [3, 4]]), 0.0001);
    }

    /**
     * Returns null for nested array with zero denominator.
     */
    #[Test]
    public function toFloatReturnsNullForNestedArrayWithZeroDenominator(): void
    {
        self::assertNull($this->converter->toFloat([[1, 0]]));
    }

    /**
     * Converts a direct rational pair [numerator, denominator] array.
     */
    #[Test]
    public function toFloatConvertsDirectRationalPair(): void
    {
        self::assertEqualsWithDelta(2.5, $this->converter->toFloat([5, 2]), 0.0001);
    }

    /**
     * Returns null for direct pair with zero denominator.
     */
    #[Test]
    public function toFloatReturnsNullForDirectPairWithZeroDenominator(): void
    {
        self::assertNull($this->converter->toFloat([5, 0]));
    }

    /**
     * Converts a single-element array.
     */
    #[Test]
    public function toFloatConvertsSingleElementArray(): void
    {
        self::assertSame(42.0, $this->converter->toFloat([42]));
    }

    /**
     * Returns null for empty array.
     */
    #[Test]
    public function toFloatReturnsNullForEmptyArray(): void
    {
        self::assertNull($this->converter->toFloat([]));
    }

    /**
     * Converts an array of ExifRationals, extracting the first.
     */
    #[Test]
    public function toFloatConvertsArrayOfExifRationals(): void
    {
        self::assertEqualsWithDelta(
            3.0,
            $this->converter->toFloat([new ExifRational(6, 2)]),
            0.0001,
        );
    }

    /**
     * Converts a whitespace-padded numeric string.
     */
    #[Test]
    public function toFloatTrimsWhitespaceFromString(): void
    {
        self::assertSame(5.0, $this->converter->toFloat('  5  '));
    }

    /**
     * Returns null for empty string (after trim).
     */
    #[Test]
    public function toFloatReturnsNullForEmptyStringAfterTrim(): void
    {
        self::assertNull($this->converter->toFloat('   '));
    }

    /**
     * Converts a triplet ExifRationalList to a float vector.
     */
    #[Test]
    public function tripletToFloatVectorConvertsThreeRationals(): void
    {
        $list = new ExifRationalList([
            new ExifRational(1, 2),
            new ExifRational(3, 4),
            new ExifRational(5, 6),
        ]);

        $result = $this->converter->tripletToFloatVector($list);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(0.5, $result[0], 0.0001);
        self::assertEqualsWithDelta(0.75, $result[1], 0.0001);
        self::assertEqualsWithDelta(0.833, $result[2], 0.001);
    }

    /**
     * Returns null for a triplet list with wrong count.
     */
    #[Test]
    public function tripletToFloatVectorReturnsNullForWrongCount(): void
    {
        $list = new ExifRationalList([
            new ExifRational(1, 2),
            new ExifRational(3, 4),
        ]);

        self::assertNull($this->converter->tripletToFloatVector($list));
    }

    /**
     * Returns null for a triplet with a zero denominator component.
     */
    #[Test]
    public function tripletToFloatVectorReturnsNullForZeroDenominator(): void
    {
        $list = new ExifRationalList([
            new ExifRational(1, 2),
            new ExifRational(3, 0),
            new ExifRational(5, 6),
        ]);

        self::assertNull($this->converter->tripletToFloatVector($list));
    }

    /**
     * isUnknownDenominator returns true for integer zero.
     */
    #[Test]
    public function isUnknownDenominatorReturnsTrueForIntZero(): void
    {
        self::assertTrue($this->converter->isUnknownDenominator(0));
    }

    /**
     * isUnknownDenominator returns true for float zero.
     */
    #[Test]
    public function isUnknownDenominatorReturnsTrueForFloatZero(): void
    {
        self::assertTrue($this->converter->isUnknownDenominator(0.0));
    }

    /**
     * isUnknownDenominator returns false for non-zero value.
     */
    #[Test]
    public function isUnknownDenominatorReturnsFalseForNonZero(): void
    {
        self::assertFalse($this->converter->isUnknownDenominator(1));
    }
}
