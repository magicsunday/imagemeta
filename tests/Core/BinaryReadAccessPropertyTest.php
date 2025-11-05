<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Contracts\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function random_bytes;
use function random_int;
use function substr;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Property-based regression tests that ensure the binary access implementations stay aligned.
 */
#[CoversClass(MemoryBuffer::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class BinaryReadAccessPropertyTest extends TestCase
{
    use CreatesTempStream;

    #[Test]
    public function memoryBufferAndStreamBehaveIdenticallyForRandomPayloads(): void
    {
        for ($iteration = 0; $iteration < 25; ++$iteration) {
            $length  = random_int(64, 256);
            $payload = random_bytes($length);

            $buffer = new MemoryBuffer($payload);
            $stream = new Stream($this->createTempStream($payload), $length);

            self::assertSame(
                $this->consumeReader($buffer),
                $this->consumeReader($stream),
            );
        }
    }

    #[Test]
    public function streamWindowMatchesBufferViewForRandomSlices(): void
    {
        for ($iteration = 0; $iteration < 25; ++$iteration) {
            $length  = random_int(128, 256);
            $payload = random_bytes($length);

            $stream = new Stream($this->createTempStream($payload), $length);

            $maxOffset = $length - 64;
            $offset    = random_int(0, $maxOffset);
            $minWindow = 64;
            $maxWindow = $length - $offset;
            if ($maxWindow < $minWindow) {
                $maxWindow = $minWindow;
            }

            $windowLength = $minWindow === $maxWindow ? $minWindow : random_int($minWindow, $maxWindow);

            $window = new StreamWindow($stream, $offset, $windowLength);
            $buffer = new MemoryBuffer(substr($payload, $offset, $windowLength));

            self::assertSame(
                $this->consumeReader($buffer),
                $this->consumeReader($window),
            );
        }
    }

    /**
     * Collects a selection of read operations to compare different implementations deterministically.
     *
     * @return array<int, int|string>
     */
    private function consumeReader(BinaryReadAccessInterface $reader): array
    {
        $reader->seek(UInt64::fromInt(0), SEEK_SET);

        $results   = [];
        $results[] = $reader->read(UInt64::fromInt(3));
        $results[] = $reader->read(2);
        $results[] = $reader->readU8();
        $results[] = $reader->readU16BE();
        $results[] = $reader->readU32BE();
        $results[] = $reader->readU64BE()->toHex();

        $reader->seek(-4, SEEK_CUR);
        $results[] = $reader->read(4);

        $reader->seek(-8, SEEK_END);
        $results[] = $reader->read(4);

        $reader->seek(UInt64::fromInt(0), SEEK_SET);
        $results[] = $reader->tell();

        $reader->seek(0, SEEK_END);
        $results[] = $reader->tell();

        return $results;
    }
}
