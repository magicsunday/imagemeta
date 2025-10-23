<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core {
    use MagicSunday\ImageMeta\Tests\Core\MemoryBuffer\MemoryBufferTest;

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

namespace MagicSunday\ImageMeta\Tests\Core\MemoryBuffer {
    use MagicSunday\ImageMeta\Core\BoundsError;
    use MagicSunday\ImageMeta\Core\MemoryBuffer;
    use MagicSunday\ImageMeta\Core\ParseError;
    use PHPUnit\Framework\Attributes\After;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;

    /**
     * Unit tests for the bounds-checked in-memory buffer abstraction.
     */
    final class MemoryBufferTest extends TestCase
    {
        private static bool $forceShortRead = false;

        /**
         * Delegates to the global \substr implementation while allowing tests to force short reads.
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

        #[After]
        protected function resetOverrides(): void
        {
            self::$forceShortRead = false;
        }

        #[Test]
        public function testSeekMovesCursorWithinBounds(): void
        {
            $buffer = new MemoryBuffer('abcdef');

            $buffer->seek(3);
            self::assertSame(3, $buffer->tell());
            self::assertSame('de', $buffer->read(2));
            self::assertSame(5, $buffer->tell());
        }

        #[Test]
        public function testSeekThrowsBoundsErrorOutsideBuffer(): void
        {
            $buffer = new MemoryBuffer('abc');

            $this->expectException(BoundsError::class);
            $buffer->seek(4);
        }

        #[Test]
        public function testReadReturnsRequestedBytes(): void
        {
            $buffer = new MemoryBuffer('MagicSunday');

            self::assertSame('Magic', $buffer->read(5));
            self::assertSame(5, $buffer->tell());
            self::assertSame('Sunday', $buffer->read(6));
            self::assertSame(11, $buffer->tell());
        }

        #[Test]
        public function testReadThrowsBoundsErrorWhenLengthTooLarge(): void
        {
            $buffer = new MemoryBuffer('meta');

            $buffer->read(4);

            $this->expectException(BoundsError::class);
            $buffer->read(1);
        }

        #[Test]
        public function testReadThrowsParseErrorOnShortRead(): void
        {
            $buffer = new MemoryBuffer('buffer');

            self::$forceShortRead = true;

            $this->expectException(ParseError::class);
            $buffer->read(2);
        }

        #[Test]
        public function testReadUnsignedIntegersRespectEndianness(): void
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
            self::assertSame(0x0123456789ABCDEF, $buffer->readU64LE());
            self::assertSame(0x0123456789ABCDEF, $buffer->readU64BE());
            self::assertSame($buffer->size(), $buffer->tell());
        }
    }
}
