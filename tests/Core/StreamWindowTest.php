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
 * Unit tests covering the stream window cursor, bounds checks, and integer helpers.
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
     * Verifies that $window->size() equals 5.
     *
     * @return void
     */
    #[Test]
    public function sizeReportsConfiguredLengthAndCursorStartsAtZero(): void
    {
        $window = new StreamWindow($this->createStream('0123456789'), 2, 5);

        self::assertSame(5, $window->size());
        self::assertSame(0, $window->tell());
    }

    /**
     * Verifies that $window->tell() equals 3.
     *
     * @return void
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
     * Verifies that BoundsError::class is thrown.
     *
     * @return void
     */
    #[Test]
    public function seekThrowsBoundsErrorOutsideWindow(): void
    {
        $window = new StreamWindow($this->createStream('abcdefghij'), 0, 4);

        $this->expectException(BoundsError::class);
        $window->seek(5);
    }

    /**
     * Verifies that $window->read(6) equals 'Sunday'.
     *
     * @return void
     */
    #[Test]
    public function readReturnsRequestedBytesAndAdvancesCursor(): void
    {
        $window = new StreamWindow($this->createStream('MagicSunday'), 5, 6);

        self::assertSame('Sunday', $window->read(6));
        self::assertSame(6, $window->tell());
    }

    /**
     * Verifies that BoundsError::class is thrown.
     *
     * @return void
     */
    #[Test]
    public function readThrowsBoundsErrorWhenRequestCrossesEnd(): void
    {
        $window = new StreamWindow($this->createStream('Meta'), 1, 2);

        $this->expectException(BoundsError::class);
        $window->read(3);
    }

    /**
     * Verifies that $window->readU8() equals 0xAA.
     *
     * @return void
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
     * Verifies that BoundsError::class is thrown.
     *
     * @return void
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
