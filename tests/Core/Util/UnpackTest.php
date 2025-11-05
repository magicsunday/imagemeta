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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;

/**
 * Tests for the Unpack utility class.
 */
#[CoversClass(Unpack::class)]
#[UsesClass(UInt64::class)]
final class UnpackTest extends TestCase
{
    #[Test]
    public function unpacksIntegerValue(): void
    {
        $bytes = chr(0x12) . chr(0x34);
        
        $result = Unpack::int('n', $bytes, 'test');
        
        self::assertSame(0x1234, $result);
    }

    #[Test]
    public function unpacksFloatValue(): void
    {
        $bytes = pack('f', 3.14);
        
        $result = Unpack::float('f', $bytes, 'test');
        
        self::assertEqualsWithDelta(3.14, $result, 0.01);
    }

    #[Test]
    public function throwsParseErrorOnInvalidFormat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Failed to unpack test');
        
        Unpack::int('invalid', '', 'test');
    }

    #[Test]
    public function combinesTwoUint32Values(): void
    {
        $result = Unpack::combineUint32(0x12345678, 0x9ABCDEF0);
        
        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    #[Test]
    public function unpacksUint64BigEndian(): void
    {
        $bytes = pack('N', 0x12345678) . pack('N', 0x9ABCDEF0);
        
        $result = Unpack::uint64($bytes, false, 'test');
        
        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    #[Test]
    public function unpacksUint64LittleEndian(): void
    {
        $bytes = pack('V', 0x9ABCDEF0) . pack('V', 0x12345678);
        
        $result = Unpack::uint64($bytes, true, 'test');
        
        self::assertSame(0x12345678, $result->high());
        self::assertSame(0x9ABCDEF0, $result->low());
    }

    #[Test]
    public function throwsParseErrorOnInvalidUint64Bytes(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Failed to unpack test');
        
        Unpack::uint64('short', false, 'test');
    }
}
