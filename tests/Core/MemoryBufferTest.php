<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core {
    use MagicSunday\ImageMeta\Tests\Core\MemoryBufferTest;

    use function function_exists;

    if (!function_exists(__NAMESPACE__ . '\\substr')) {
        /**
         * Test hook that allows forcing short-read behaviour for MemoryBuffer::read().
         */
        function substr(string $string, int $offset, ?int $length = null): string
        {
            return MemoryBufferTest::invokeSubstrProxy($string, $offset, $length);
        }
    }
}

namespace MagicSunday\ImageMeta\Tests\Core {
    use MagicSunday\ImageMeta\Core\BoundsError;
    use MagicSunday\ImageMeta\Core\ByteReader;
    use MagicSunday\ImageMeta\Core\MemoryBuffer;
    use MagicSunday\ImageMeta\Core\ParseError;
    use MagicSunday\ImageMeta\Core\Util\UInt64;
    use MagicSunday\ImageMeta\Core\Util\Unpack;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\Attributes\UsesClass;
    use PHPUnit\Framework\TestCase;

    use function substr;

    use const PHP_INT_MAX;

    /**
     * Exercises the MemoryBuffer implementation for bounds-checked reads and seeks.
     * It uses the test hook to force short reads and verify ParseError handling.
     * The suite validates ByteReader integration and integer decoding on buffer data.
     * It confirms that offsets and lengths are enforced for both int and UInt64 inputs.
     *
     * @internal
     */
    #[CoversClass(MemoryBuffer::class)]
    #[UsesClass(ByteReader::class)]
    #[UsesClass(UInt64::class)]
    #[UsesClass(Unpack::class)]
    final class MemoryBufferTest extends TestCase
    {
        private static bool $forceShortRead = false;

        protected function setUp(): void
        {
            self::$forceShortRead = false;
        }

        protected function tearDown(): void
        {
            self::$forceShortRead = false;
        }

        /**
         * Delegates to the global \substr implementation while allowing tests to force short reads.
         * This lets tests trigger the short-read ParseError path deterministically.
         *
         * @param string   $string Source string to slice.
         * @param int      $offset Starting offset to read from.
         * @param int|null $length Optional maximum number of bytes to read.
         *
         * @return string Portion of the input string returned by \substr.
         */
        public static function invokeSubstrProxy(string $string, int $offset, ?int $length = null): string
        {
            if (self::$forceShortRead && $length !== null && $length > 0) {
                --$length;
            }

            if ($length === null) {
                return substr($string, $offset);
            }

            if ($length < 0) {
                $length = 0;
            }

            return substr($string, $offset, $length);
        }

        /**
         * Seeks to an in-bounds offset and reads a byte range.
         * This confirms the cursor moves correctly across seek/read operations.
         */
        #[Test]
        public function seekMovesCursorWithinBounds(): void
        {
            $buffer = new MemoryBuffer('abcdef');

            $buffer->seek(3);
            self::assertSame(3, $buffer->tell());
            self::assertSame('de', $buffer->read(2));
            self::assertSame(5, $buffer->tell());
        }

        /**
         * Seeks past the buffer length to trigger a bounds violation.
         * This verifies that MemoryBuffer enforces strict seek limits.
         */
        #[Test]
        public function seekThrowsBoundsErrorOutsideBuffer(): void
        {
            $buffer = new MemoryBuffer('abc');

            $this->expectException(BoundsError::class);
            $buffer->seek(4);
        }

        /**
         * Reads sequential chunks and validates cursor advancement.
         * This confirms reads return exact bytes and update the position.
         */
        #[Test]
        public function readReturnsRequestedBytes(): void
        {
            $buffer = new MemoryBuffer('MagicSunday');

            self::assertSame('Magic', $buffer->read(5));
            self::assertSame(5, $buffer->tell());
            self::assertSame('Sunday', $buffer->read(6));
            self::assertSame(11, $buffer->tell());
        }

        /**
         * Reads to the end and then attempts to read past it.
         * This ensures the bounds guard triggers on over-read.
         */
        #[Test]
        public function readThrowsBoundsErrorWhenLengthTooLarge(): void
        {
            $buffer = new MemoryBuffer('meta');

            $buffer->read(4);

            $this->expectException(BoundsError::class);
            $buffer->read(1);
        }

        /**
         * Requests PHP_INT_MAX bytes from a buffer positioned at offset 1.
         * On 32-bit PHP the naive pos+len addition wraps to a negative value and the
         * bounds check silently passes; the guard must use subtraction to stay
         * overflow-safe on every platform.
         */
        #[Test]
        public function readThrowsBoundsErrorOnOverflowingLength(): void
        {
            $buffer = new MemoryBuffer('abcde');
            $buffer->read(1);

            $this->expectException(BoundsError::class);
            $this->expectExceptionCode(1031);
            $buffer->read(PHP_INT_MAX);
        }

        /**
         * Forces a short read via the substr hook to trigger ParseError.
         * This validates the error path when the read returns fewer bytes than requested.
         */
        #[Test]
        public function readThrowsParseErrorOnShortRead(): void
        {
            $buffer = new MemoryBuffer('buffer');

            self::$forceShortRead = true;

            $this->expectException(ParseError::class);
            $buffer->read(2);
        }

        /**
         * Reads mixed-endian integer values from a single payload.
         * This confirms endianness handling for 8/16/32/64-bit reads and cursor position at EOF.
         */
        #[Test]
        public function readUnsignedIntegersRespectEndianness(): void
        {
            $payload = pack('C', 0x7F)
                . pack('v', 0x3412)
                . pack('n', 0x1234)
                . pack('V', 0x78563412)
                . pack('N', 0x12345678)
                . pack('V2', 0x89ABCDEF, 0x01234567)
                . pack('N2', 0x01234567, 0x89ABCDEF);

            $buffer = new MemoryBuffer($payload);

            self::assertSame(0x7F, $buffer->readU8());
            self::assertSame(0x3412, $buffer->readU16LE());
            self::assertSame(0x1234, $buffer->readU16BE());
            self::assertSame(0x78563412, $buffer->readU32LE());
            self::assertSame(0x12345678, $buffer->readU32BE());
            self::assertSame(0x0123456789ABCDEF, $buffer->readU64LE()->toInt('test value'));
            self::assertSame(0x0123456789ABCDEF, $buffer->readU64BE()->toInt('test value'));
            self::assertSame($buffer->size(), $buffer->tell());
        }
    }
}
