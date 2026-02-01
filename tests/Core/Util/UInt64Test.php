<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Util;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises UInt64 construction and conversions from int and word parts.
 * It verifies high/low accessors and boundary handling for 64-bit values.
 * The suite covers parsing errors on invalid or negative inputs.
 * This keeps UInt64 behavior predictable for large offsets and sizes.
 */
#[CoversClass(UInt64::class)]
final class UInt64Test extends TestCase
{
    /**
     * Constructs a UInt64 from explicit high/low parts.
     * It verifies the accessors return the exact values provided.
     *
     * @return void
     */
    #[Test]
    public function constructsFromHighAndLowParts(): void
    {
        $value = new UInt64(0x12345678, 0x9ABCDEF0);

        self::assertSame(0x12345678, $value->high());
        self::assertSame(0x9ABCDEF0, $value->low());
    }

    /**
     * Builds a UInt64 from two unsigned 32-bit words.
     * It ensures the high and low words are preserved as-is.
     *
     * @return void
     */
    #[Test]
    public function createsFromTwoUnsigned32BitParts(): void
    {
        $value = UInt64::fromUInt32(0x00000001, 0x00000000);

        self::assertSame(0x00000001, $value->high());
        self::assertSame(0x00000000, $value->low());
    }

    /**
     * Converts a non-negative integer larger than 32 bits into high/low parts.
     * It confirms the high word captures the overflow beyond the low word.
     *
     * @return void
     */
    #[Test]
    public function createsFromNonNegativeInteger(): void
    {
        $value = UInt64::fromInt(0x100000000); // 2^32

        self::assertSame(1, $value->high());
        self::assertSame(0, $value->low());
    }

    /**
     * Rejects negative input when constructing from an integer.
     * It asserts a ParseError is thrown for invalid signed values.
     *
     * @return void
     */
    #[Test]
    public function throwsExceptionForNegativeInteger(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Cannot create UInt64 from a negative integer.');

        UInt64::fromInt(-1);
    }

    /**
     * Converts a small UInt64 back to a native integer.
     * It verifies the conversion succeeds within the supported range.
     *
     * @return void
     */
    #[Test]
    public function convertsSmallValueToInt(): void
    {
        $value = UInt64::fromInt(12345);

        self::assertSame(12345, $value->toInt('test'));
    }

    /**
     * Detects zero-valued UInt64 instances.
     * It confirms non-zero values are not treated as zero.
     *
     * @return void
     */
    #[Test]
    public function detectsZeroValue(): void
    {
        $zero    = new UInt64(0, 0);
        $nonZero = new UInt64(0, 1);

        self::assertTrue($zero->isZero());
        self::assertFalse($nonZero->isZero());
    }

    /**
     * Rejects conversion when the value exceeds the supported integer range.
     * It asserts a ParseError is thrown to prevent truncation.
     *
     * @return void
     */
    #[Test]
    public function throwsParseErrorWhenConvertingLargeValueToInt(): void
    {
        $value = new UInt64(0xFFFFFFFF, 0xFFFFFFFF);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('test exceeds supported integer range.');

        $value->toInt('test');
    }

    /**
     * Converts the value to a string representation for small values.
     * It confirms the decimal formatting matches the low word.
     *
     * @return void
     */
    #[Test]
    public function convertsToString(): void
    {
        $value = new UInt64(0, 12345);

        self::assertSame('12345', (string) $value->low());
    }

    /**
     * Adds a small integer without overflowing the low word.
     * It verifies that the high word remains unchanged.
     *
     * @return void
     */
    #[Test]
    public function addsUnsignedInteger(): void
    {
        $value  = new UInt64(0, 100);
        $result = $value->addSmall(50);

        self::assertSame(0, $result->high());
        self::assertSame(150, $result->low());
    }

    /**
     * Adds a small integer that overflows the low word.
     * It verifies the carry increments the high word and wraps the low word.
     *
     * @return void
     */
    #[Test]
    public function handlesOverflowInAddition(): void
    {
        $value  = new UInt64(0, 0xFFFFFFFF);
        $result = $value->addSmall(2);

        self::assertSame(1, $result->high());
        self::assertSame(1, $result->low());
    }

    /**
     * Compares values with identical high words and differing low words.
     * It verifies comparison results for less-than, greater-than, and equality.
     *
     * @return void
     */
    #[Test]
    public function comparesWithOtherUInt64(): void
    {
        $smaller = new UInt64(0, 100);
        $larger  = new UInt64(0, 200);
        $equal   = new UInt64(0, 100);

        self::assertTrue($smaller->compare($larger) < 0);
        self::assertFalse($larger->compare($smaller) < 0);
        self::assertFalse($smaller->compare($equal) < 0);

        self::assertTrue($larger->compare($smaller) > 0);
        self::assertFalse($smaller->compare($equal) > 0);
    }

    /**
     * Compares values with different high words.
     * It confirms the high word dominates ordering before the low word.
     *
     * @return void
     */
    #[Test]
    public function comparesHighPartFirst(): void
    {
        $smaller = new UInt64(1, 0xFFFFFFFF);
        $larger  = new UInt64(2, 0);

        self::assertTrue($smaller->compare($larger) < 0);
        self::assertTrue($larger->compare($smaller) > 0);
    }
}
