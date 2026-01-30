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
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Unit tests verifying the behaviour of the bounds-checked stream wrapper.
 */
#[CoversClass(Stream::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class StreamTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Verifies that $stream->tell() equals 0.
     *
     * @return void
     */
    #[Test]
    public function readsUnsignedIntegersSequentially(): void
    {
        $payload = pack('n', 0xBEEF)
            . pack('N', 0x10203040)
            . pack('N2', 0x01234567, 0x89ABCDEF);

        $stream = new Stream($this->createTempStream($payload), strlen($payload));

        self::assertSame(0, $stream->tell());
        self::assertSame(0xBEEF, $stream->readU16BE());
        self::assertSame(2, $stream->tell());
        self::assertSame(0x10203040, $stream->readU32BE());
        self::assertSame(6, $stream->tell());
        self::assertSame(0x0123456789ABCDEF, $stream->readU64BE()->toInt('test value'));
        self::assertSame(14, $stream->tell());
    }

    /**
     * Verifies that $chunk equals 'Magic'.
     *
     * @return void
     */
    #[Test]
    public function readReturnsRequestedBytesAndAdvancesCursor(): void
    {
        $payload = 'MagicSunday';

        $stream = new Stream($this->createTempStream($payload), strlen($payload));

        $chunk = $stream->read(5);

        self::assertSame('Magic', $chunk);
        self::assertSame(5, $stream->tell());

        $stream->seek(5);
        self::assertSame('Sunday', $stream->read(6));
        self::assertSame(11, $stream->tell());
    }

    /**
     * Verifies that BoundsError::class is thrown.
     *
     * @return void
     */
    #[Test]
    public function readThrowsBoundsErrorWhenRequestCrossesEnd(): void
    {
        $payload = 'Image';

        $stream = new Stream($this->createTempStream($payload), strlen($payload));

        $stream->read(5);

        $this->expectException(BoundsError::class);
        $stream->read(1);
    }

    /**
     * Verifies that BoundsError::class is thrown.
     *
     * @return void
     */
    #[Test]
    public function seekThrowsBoundsErrorWhenOffsetIsOutsideStream(): void
    {
        $payload = 'Meta';

        $stream = new Stream($this->createTempStream($payload), strlen($payload));

        $this->expectException(BoundsError::class);
        $stream->seek(8);
    }
}
