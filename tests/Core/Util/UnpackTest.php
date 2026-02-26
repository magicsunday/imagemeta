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
use MagicSunday\ImageMeta\Core\Util\Unpack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;

/**
 * Tests the Unpack helpers for decoding numeric values from byte strings.
 * It covers integer and float extraction and validates 64-bit handling via UInt64.
 * The suite asserts that invalid formats raise ParseError with context.
 * This ensures binary decoding remains strict and reliable for parsers.
 */
#[CoversClass(Unpack::class)]
#[UsesClass(UInt64::class)]
final class UnpackTest extends TestCase
{
    /**
     * Unpacks a two-byte integer using a big-endian format.
     * It confirms Unpack::int returns the expected scalar value.
     */
    #[Test]
    public function unpacksIntegerValue(): void
    {
        $bytes = chr(0x12) . chr(0x34);

        $result = Unpack::int('n', $bytes, 'test');

        self::assertSame(0x1234, $result);
    }

    /**
     * Unpacks a float value from the packed byte sequence.
     * It validates floating-point extraction within a tolerance.
     */
    #[Test]
    public function unpacksFloatValue(): void
    {
        $bytes = pack('f', 3.14);

        $result = Unpack::float('f', $bytes, 'test');

        self::assertEqualsWithDelta(3.14, $result, 0.01);
    }

    /**
     * Passes an invalid unpack format to force a failure.
     * It asserts a ParseError is raised with the expected message.
     */
    #[Test]
    public function throwsParseErrorOnInvalidFormat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Failed to unpack test');

        Unpack::int('invalid', '', 'test');
    }

    /**
     * Supplies too few bytes for a 32-bit integer unpack.
     * It asserts the helper fails deterministically with ParseError.
     */
    #[Test]
    public function throwsParseErrorOnShortFixedWidthPayload(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Failed to unpack test');

        Unpack::int('N', "\x01", 'test');
    }

    /**
     * Combines two 32-bit words into a UInt64 instance.
     * It confirms the high and low parts are preserved correctly.
     */
    #[Test]
    public function combinesTwoUint32Values(): void
    {
        $result = Unpack::combineUint32(0x12345678, 0x9ABCDEF0);

        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    /**
     * Unpacks a 64-bit unsigned integer in big-endian order.
     * It verifies the high/low words match the original packed values.
     */
    #[Test]
    public function unpacksUint64BigEndian(): void
    {
        $bytes = pack('N', 0x12345678) . pack('N', 0x9ABCDEF0);

        $result = Unpack::uint64($bytes, false, 'test');

        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    /**
     * Unpacks a 64-bit unsigned integer in little-endian order.
     * It confirms the word order is reversed as expected.
     */
    #[Test]
    public function unpacksUint64LittleEndian(): void
    {
        $bytes = pack('V', 0x9ABCDEF0) . pack('V', 0x12345678);

        $result = Unpack::uint64($bytes, true, 'test');

        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    /**
     * Supplies too few bytes to decode a 64-bit value.
     * It asserts a ParseError is thrown for truncated input.
     */
    #[Test]
    public function throwsParseErrorOnInvalidUint64Bytes(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Failed to unpack test');

        Unpack::uint64('short', false, 'test');
    }
}
