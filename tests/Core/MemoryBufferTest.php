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
    use PHPUnit\Framework\Attributes\After;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\UsesClass;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;

    /**
     * Unit tests for the bounds-checked in-memory buffer abstraction.
     */
    #[CoversClass(MemoryBuffer::class)]
    #[UsesClass(ByteReader::class)]
    #[UsesClass(UInt64::class)]
    #[UsesClass(Unpack::class)]
    final class MemoryBufferTest extends TestCase
    {
        private static bool $forceShortRead = false;

        /**
         * Delegates to the global \substr implementation while allowing tests to force short reads.
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
                return \substr($string, $offset);
            }

            if ($length < 0) {
                $length = 0;
            }

            return \substr($string, $offset, $length);
        }

        /**
         * Resets the short-read override flag after each test to avoid cross-test leakage.
         */
        #[After]
        protected function resetOverrides(): void
        {
            self::$forceShortRead = false;
        }

        /**
         * Seeks to a position inside the buffer and verifies that the cursor reflects the move and
         * subsequent reads advance it as expected.
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
         * Attempts to seek beyond the available bytes to ensure a BoundsError is raised.
         */
        #[Test]
        public function seekThrowsBoundsErrorOutsideBuffer(): void
        {
            $buffer = new MemoryBuffer('abc');

            $this->expectException(BoundsError::class);
            $buffer->seek(4);
        }

        /**
         * Reads sequential slices and confirms both the returned data and cursor position match the
         * requested byte count.
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
         * Ensures that reading past the end of the buffer throws a BoundsError.
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
         * Forces the proxy \substr implementation to perform a short read so MemoryBuffer raises a
         * ParseError as expected for truncated data.
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
         * Reads unsigned integers of varying widths to confirm endian-specific helpers decode the
         * packed payload and advance the cursor correctly.
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
