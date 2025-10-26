<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Stream;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Unit tests verifying the behaviour of the bounds-checked stream wrapper.
 */
final class StreamTest extends TestCase
{
    /**
     * Ensures sequential big-endian integer reads advance the cursor as expected.
     */
    #[Test]
    public function testReadsUnsignedIntegersSequentially(): void
    {
        $payload = pack('n', 0xBEEF)
            . pack('N', 0x10203040)
            . pack('N2', 0x01234567, 0x89ABCDEF);

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        self::assertSame(0, $stream->tell());
        self::assertSame(0xBEEF, $stream->readU16BE());
        self::assertSame(2, $stream->tell());
        self::assertSame(0x10203040, $stream->readU32BE());
        self::assertSame(6, $stream->tell());
        self::assertSame(0x0123456789ABCDEF, $stream->readU64BE()->toInt('test value'));
        self::assertSame(14, $stream->tell());
    }

    /**
     * Verifies chunked reads return the requested bytes and update the position.
     */
    #[Test]
    public function testReadReturnsRequestedBytesAndAdvancesCursor(): void
    {
        $payload = 'MagicSunday';

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $chunk = $stream->read(5);

        self::assertSame('Magic', $chunk);
        self::assertSame(5, $stream->tell());

        $stream->seek(5);
        self::assertSame('Sunday', $stream->read(6));
        self::assertSame(11, $stream->tell());
    }

    /**
     * Checks that requesting bytes past the end raises a BoundsError exception.
     */
    #[Test]
    public function testReadThrowsBoundsErrorWhenRequestCrossesEnd(): void
    {
        $payload = 'Image';

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $stream->read(5);

        $this->expectException(BoundsError::class);
        $stream->read(1);
    }

    /**
     * Asserts seeking beyond the declared length triggers a BoundsError exception.
     */
    #[Test]
    public function testSeekThrowsBoundsErrorWhenOffsetIsOutsideStream(): void
    {
        $payload = 'Meta';

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $this->expectException(BoundsError::class);
        $stream->seek(8);
    }
}
