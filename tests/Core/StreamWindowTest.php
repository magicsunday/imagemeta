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
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Tests StreamWindow behavior for window-relative reads, seeks, and bounds.
 * It verifies that size and cursor positions are relative to the window, not the parent stream.
 * The suite exercises integer decoding within the windowed slice.
 * This confirms that window offsets and limits are enforced consistently.
 */
#[CoversClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class StreamWindowTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Creates a window with a fixed offset and length.
     * It confirms size reporting and that the cursor starts at zero within the window.
     */
    #[Test]
    public function sizeReportsConfiguredLengthAndCursorStartsAtZero(): void
    {
        $window = new StreamWindow($this->createStream('0123456789'), 2, 5);

        self::assertSame(5, $window->size());
        self::assertSame(0, $window->tell());
    }

    /**
     * Seeks to valid positions within the window.
     * It confirms tell reports window-relative offsets.
     */
    #[Test]
    public function seekMovesCursorWithinWindowBounds(): void
    {
        $window = new StreamWindow($this->createStream('abcdefghij'), 1, 6);

        $window->seek(3);
        self::assertSame(3, $window->tell());

        $window->seek(6);
        self::assertSame(6, $window->tell());
    }

    /**
     * Attempts to seek beyond the window length.
     * It asserts a BoundsError is raised for out-of-range seeks.
     */
    #[Test]
    public function seekThrowsBoundsErrorOutsideWindow(): void
    {
        $window = new StreamWindow($this->createStream('abcdefghij'), 0, 4);

        $this->expectException(BoundsError::class);
        $window->seek(5);
    }

    /**
     * Reads the entire window and advances the cursor to the end.
     * It confirms reads are window-relative, not stream-relative.
     */
    #[Test]
    public function readReturnsRequestedBytesAndAdvancesCursor(): void
    {
        $window = new StreamWindow($this->createStream('MagicSunday'), 5, 6);

        self::assertSame('Sunday', $window->read(6));
        self::assertSame(6, $window->tell());
    }

    /**
     * Requests more bytes than remain in the window.
     * It asserts a BoundsError is raised for window over-reads.
     */
    #[Test]
    public function readThrowsBoundsErrorWhenRequestCrossesEnd(): void
    {
        $window = new StreamWindow($this->createStream('Meta'), 1, 2);

        $this->expectException(BoundsError::class);
        $window->read(3);
    }

    /**
     * Reads multiple unsigned integer types from a single window payload.
     * It confirms helper methods honor endianness and advance to the window end.
     */
    #[Test]
    public function unsignedIntegerHelpersReadSequentially(): void
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
     * Consumes part of the payload and then attempts a 64-bit read.
     * It confirms helper methods enforce bounds when insufficient bytes remain.
     */
    #[Test]
    public function unsignedIntegerHelpersThrowBoundsErrorOnShortData(): void
    {
        $payload = pack('C', 0x01) . pack('n', 0x0203);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $window->readU8();

        $this->expectException(BoundsError::class);
        $window->readU64BE();
    }

    /**
     * Attempts a 32-bit read when only 3 bytes remain in the window.
     * It confirms a BoundsError is raised before any bytes are consumed.
     */
    #[Test]
    public function throwsBoundsErrorOnU32WithFewerThanFourBytesRemaining(): void
    {
        $payload = pack('C3', 0x01, 0x02, 0x03);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $this->expectException(BoundsError::class);
        $window->readU32BE();
    }

    /**
     * Attempts a 16-bit read when only 1 byte remains in the window.
     * It confirms a BoundsError is raised when the read would cross the window end.
     */
    #[Test]
    public function throwsBoundsErrorOnU16WithOnlyOneByteRemaining(): void
    {
        $payload = pack('C', 0xFF);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $this->expectException(BoundsError::class);
        $window->readU16BE();
    }

    /**
     * Attempts an 8-bit read when the cursor is already at the window end.
     * It confirms a BoundsError is raised when zero bytes remain.
     */
    #[Test]
    public function throwsBoundsErrorOnU8WithZeroBytesRemaining(): void
    {
        $payload = pack('C', 0xAB);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $window->readU8();

        $this->expectException(BoundsError::class);
        $window->readU8();
    }

    /**
     * Reads a single byte from a window whose length is exactly one.
     * It confirms the read succeeds and the cursor advances to the window end.
     */
    #[Test]
    public function readU8SucceedsAtExactWindowBoundary(): void
    {
        $payload = pack('C', 0x7E);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        self::assertSame(0x7E, $window->readU8());
        self::assertSame(1, $window->tell());
    }

    /**
     * Advances the cursor to the window end and then requests one raw byte.
     * It confirms a BoundsError is raised when reading one byte past the window boundary.
     */
    #[Test]
    public function throwsBoundsErrorOnReadOnePastWindowEnd(): void
    {
        $payload = pack('C', 0x01);
        $window  = new StreamWindow($this->createStream($payload), 0, strlen($payload));

        $window->read(1);

        $this->expectException(BoundsError::class);
        $window->read(1);
    }

    /**
     * Creates a Stream instance populated with the provided payload.
     * This helper ensures stream windows are built on known content.
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
