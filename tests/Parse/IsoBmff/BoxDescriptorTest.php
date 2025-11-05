<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function rewind;
use function strlen;

/**
 * Exercises the ISO BMFF box descriptor value object.
 * */
#[CoversClass(BoxDescriptor::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
final class BoxDescriptorTest extends TestCase
{
    #[Test]
    public function constructorAssignsValuesVerbatim(): void
    {
        $window = $this->createWindow('0123456789abcdef', 4, 8);

        $descriptor = new BoxDescriptor(
            type: 'test',
            size: 32,
            offset: 4,
            contentOffset: 12,
            contentSize: 20,
            window: $window,
            userType: 'abcd1234ef567890abcd1234ef567890',
        );

        self::assertSame('test', $descriptor->type);
        self::assertSame(32, $descriptor->size);
        self::assertSame(4, $descriptor->offset);
        self::assertSame(12, $descriptor->contentOffset);
        self::assertSame(20, $descriptor->contentSize);
        self::assertSame($window, $descriptor->window);
        self::assertSame('abcd1234ef567890abcd1234ef567890', $descriptor->userType);
    }

    #[Test]
    public function descriptorsDoNotShareStateAccidentally(): void
    {
        $windowA = $this->createWindow('abcdefghij', 2, 4);
        $windowB = $this->createWindow('abcdefghij', 5, 3);

        $first  = new BoxDescriptor('aaaa', 16, 2, 6, 8, $windowA, null);
        $second = new BoxDescriptor('bbbb', 24, 3, 7, 6, $windowB, '00112233445566778899aabbccddeeff');

        self::assertNotSame($first->window, $second->window);
        self::assertNotSame($first->type, $second->type);
        self::assertNotSame($first->size, $second->size);
        self::assertNotSame($first->offset, $second->offset);
        self::assertNotSame($first->contentOffset, $second->contentOffset);
        self::assertNotSame($first->contentSize, $second->contentSize);
        self::assertNotSame($first->userType, $second->userType);
    }

    private function createWindow(string $data, int $offset, int $length): StreamWindow
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        $bytesWritten = fwrite($handle, $data);
        if ($bytesWritten !== strlen($data)) {
            self::fail('Unable to populate temporary stream data.');
        }

        if (rewind($handle) === false) {
            self::fail('Unable to rewind temporary stream handle.');
        }

        $stream = new Stream($handle, strlen($data));

        return $stream->window($offset, $length);
    }
}
