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
 * Tests for UInt64 unsigned 64-bit integer handling.
 */
#[CoversClass(UInt64::class)]
final class UInt64Test extends TestCase
{
    #[Test]
    public function constructsFromHighAndLowParts(): void
    {
        $value = new UInt64(0x12345678, 0x9ABCDEF0);

        self::assertSame(0x12345678, $value->high());
        self::assertSame(0x9ABCDEF0, $value->low());
    }

    #[Test]
    public function createsFromTwoUnsigned32BitParts(): void
    {
        $value = UInt64::fromUInt32(0x00000001, 0x00000000);

        self::assertSame(0x00000001, $value->high());
        self::assertSame(0x00000000, $value->low());
    }

    #[Test]
    public function createsFromNonNegativeInteger(): void
    {
        $value = UInt64::fromInt(0x100000000); // 2^32

        self::assertSame(1, $value->high());
        self::assertSame(0, $value->low());
    }

    #[Test]
    public function throwsExceptionForNegativeInteger(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Cannot create UInt64 from a negative integer.');

        UInt64::fromInt(-1);
    }

    #[Test]
    public function convertsSmallValueToInt(): void
    {
        $value = UInt64::fromInt(12345);

        self::assertSame(12345, $value->toInt('test'));
    }

    #[Test]
    public function detectsZeroValue(): void
    {
        $zero = new UInt64(0, 0);
        $nonZero = new UInt64(0, 1);

        self::assertTrue($zero->isZero());
        self::assertFalse($nonZero->isZero());
    }

    #[Test]
    public function throwsParseErrorWhenConvertingLargeValueToInt(): void
    {
        $value = new UInt64(0xFFFFFFFF, 0xFFFFFFFF);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('UInt64 value exceeds PHP_INT_MAX');

        $value->toInt('test');
    }

    #[Test]
    public function convertsToString(): void
    {
        $value = new UInt64(0, 12345);

        self::assertSame('12345', $value->toString());
    }

    #[Test]
    public function addsUnsignedInteger(): void
    {
        $value = new UInt64(0, 100);
        $result = $value->addUnsigned(50);

        self::assertSame(0, $result->high());
        self::assertSame(150, $result->low());
    }

    #[Test]
    public function handlesOverflowInAddition(): void
    {
        $value = new UInt64(0, 0xFFFFFFFF);
        $result = $value->addUnsigned(2);

        self::assertSame(1, $result->high());
        self::assertSame(1, $result->low());
    }

    #[Test]
    public function comparesWithOtherUInt64(): void
    {
        $smaller = new UInt64(0, 100);
        $larger = new UInt64(0, 200);
        $equal = new UInt64(0, 100);

        self::assertTrue($smaller->lessThan($larger));
        self::assertFalse($larger->lessThan($smaller));
        self::assertFalse($smaller->lessThan($equal));

        self::assertTrue($larger->greaterThan($smaller));
        self::assertFalse($smaller->greaterThan($larger));
        self::assertFalse($smaller->greaterThan($equal));
    }

    #[Test]
    public function comparesHighPartFirst(): void
    {
        $smaller = new UInt64(1, 0xFFFFFFFF);
        $larger = new UInt64(2, 0);

        self::assertTrue($smaller->lessThan($larger));
        self::assertTrue($larger->greaterThan($smaller));
    }
}
