<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Unit tests covering the stream window cursor, bounds checks, and integer helpers.
 */
final class StreamWindowTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Verifies that the window reports its configured length and initial cursor position.
     */
    #[Test]
    public function testSizeReportsConfiguredLengthAndCursorStartsAtZero(): void
    {
        $window = new StreamWindow($this->createStream('0123456789'), 2, 5);

        self::assertSame(5, $window->size());
        self::assertSame(0, $window->tell());
    }

    /**
     * Seeks around inside the configured span and ensures the cursor reflects the requested
     * positions.
     */
    #[Test]
    public function testSeekMovesCursorWithinWindowBounds(): void
    {
        $window = new StreamWindow($this->createStream('abcdefghij'), 1, 6);

        $window->seek(3);
        self::assertSame(3, $window->tell());

        $window->seek(6);
        self::assertSame(6, $window->tell());
    }

    /**
     * Attempts to seek beyond the window size to confirm a BoundsError is thrown.
     */
    #[Test]
    public function testSeekThrowsBoundsErrorOutsideWindow(): void
    {
        $window = new StreamWindow($this->createStream('abcdefghij'), 0, 4);

        $this->expectException(BoundsError::class);
        $window->seek(5);
    }

    /**
     * Reads bytes through the window and checks both the returned data and cursor advancement.
     */
    #[Test]
    public function testReadReturnsRequestedBytesAndAdvancesCursor(): void
    {
        $window = new StreamWindow($this->createStream('MagicSunday'), 5, 6);

        self::assertSame('Sunday', $window->read(6));
        self::assertSame(6, $window->tell());
    }

    /**
     * Ensures that read requests crossing the end of the window trigger a BoundsError.
     */
    #[Test]
    public function testReadThrowsBoundsErrorWhenRequestCrossesEnd(): void
    {
        $window = new StreamWindow($this->createStream('Meta'), 1, 2);

        $this->expectException(BoundsError::class);
        $window->read(3);
    }

    /**
     * Reads sequential unsigned integers to verify the helpers decode packed big-endian values and
     * advance the cursor appropriately.
     */
    #[Test]
    public function testUnsignedIntegerHelpersReadSequentially(): void
    {
        $payload = pack('C', 0xAA)
            . pack('n', 0xBEEF)
            . pack('N', 0x01020304)
            . pack('N2', 0x01234567, 0x89ABCDEF);

        $window = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        self::assertSame(0xAA, $window->readU8());
        self::assertSame(0xBEEF, $window->readU16BE());
        self::assertSame(0x01020304, $window->readU32BE());
        self::assertSame(0x0123456789ABCDEF, $window->readU64BE()->toInt('test value'));
        self::assertSame(strlen($payload), $window->tell());
    }

    /**
     * Confirms that insufficient remaining bytes cause the integer helper to raise a BoundsError.
     */
    #[Test]
    public function testUnsignedIntegerHelpersThrowBoundsErrorOnShortData(): void
    {
        $payload = pack('C', 0x01) . pack('n', 0x0203);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $window->readU8();

        $this->expectException(BoundsError::class);
        $window->readU64BE();
    }

    /**
     * Creates a Stream instance populated with the provided payload.
     *
     * @param string $payload Bytes to insert into the temporary stream.
     *
     * @return Stream Stream that exposes the payload for windowed reads.
     */
    private function createStream(string $payload): Stream
    {
        return new Stream($this->createTempStream($payload), strlen($payload));
    }
}
