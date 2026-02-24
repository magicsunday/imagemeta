<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;

/**
 * Covers the ByteReader helpers for integer decoding across endian modes.
 * It verifies that read methods advance the cursor and that seeking resets the position.
 * The tests validate 64-bit reads split into correct high/low words.
 * This ensures deterministic reading behavior from a fixed byte stream.
 */
#[CoversClass(ByteReader::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
final class ByteReaderTest extends TestCase
{
    /**
     * Creates a ByteReader backed by a fixed byte string.
     */
    private function createReader(string $data): ByteReader
    {
        $position = 0;

        return new ByteReader(
            read: static function (int $length) use ($data, &$position): string {
                $result = substr($data, $position, $length);
                $position += $length;

                return $result;
            },
            tell: static function () use (&$position): int {
                return $position;
            },
            seek: static function (int|UInt64 $offset) use (&$position): void {
                if ($offset instanceof UInt64) {
                    $offset = $offset->toInt('seek');
                }

                $position = $offset;
            },
            context: 'test',
        );
    }

    /**
     * Reads an unsigned 8-bit integer from a one-byte stream.
     * This confirms the reader does not apply endianness to single-byte values.
     */
    #[Test]
    public function readsUnsigned8BitInteger(): void
    {
        $reader = $this->createReader(chr(0xFF));

        self::assertSame(255, $reader->readU8());
    }

    /**
     * Reads an unsigned 16-bit integer in big-endian order.
     * This validates that byte order is interpreted correctly for multi-byte reads.
     */
    #[Test]
    public function readsUnsigned16BitBigEndianInteger(): void
    {
        $reader = $this->createReader(chr(0x12) . chr(0x34));

        self::assertSame(0x1234, $reader->readU16BE());
    }

    /**
     * Reads an unsigned 16-bit integer in little-endian order.
     * This verifies that the reader swaps byte order appropriately.
     */
    #[Test]
    public function readsUnsigned16BitLittleEndianInteger(): void
    {
        $reader = $this->createReader(chr(0x34) . chr(0x12));

        self::assertSame(0x1234, $reader->readU16LE());
    }

    /**
     * Reads an unsigned 32-bit integer in big-endian order.
     * This ensures four-byte values are assembled in the correct order.
     */
    #[Test]
    public function readsUnsigned32BitBigEndianInteger(): void
    {
        $reader = $this->createReader(chr(0x12) . chr(0x34) . chr(0x56) . chr(0x78));

        self::assertSame(0x12345678, $reader->readU32BE());
    }

    /**
     * Reads an unsigned 32-bit integer in little-endian order.
     * This confirms the reader handles low-to-high byte ordering correctly.
     */
    #[Test]
    public function readsUnsigned32BitLittleEndianInteger(): void
    {
        $reader = $this->createReader(chr(0x78) . chr(0x56) . chr(0x34) . chr(0x12));

        self::assertSame(0x12345678, $reader->readU32LE());
    }

    /**
     * Reads an unsigned 64-bit integer in big-endian order.
     * This validates high/low word assembly for 64-bit values.
     */
    #[Test]
    public function readsUnsigned64BitBigEndianInteger(): void
    {
        $data = chr(0x00) . chr(0x00) . chr(0x00) . chr(0x01) .
                chr(0x23) . chr(0x45) . chr(0x67) . chr(0x89);
        $reader = $this->createReader($data);

        $result = $reader->readU64BE();

        self::assertSame(0x00000001, $result->high());
        self::assertSame(0x23456789, $result->low());
    }

    /**
     * Reads an unsigned 64-bit integer in little-endian order.
     * This ensures the reader flips word order correctly for 64-bit values.
     */
    #[Test]
    public function readsUnsigned64BitLittleEndianInteger(): void
    {
        $data = chr(0x89) . chr(0x67) . chr(0x45) . chr(0x23) .
                chr(0x01) . chr(0x00) . chr(0x00) . chr(0x00);
        $reader = $this->createReader($data);

        $result = $reader->readU64LE();

        self::assertSame(0x00000001, $result->high());
        self::assertSame(0x23456789, $result->low());
    }

    /**
     * Reports the current position after sequential reads.
     * This confirms that read methods advance the cursor by their byte length.
     */
    #[Test]
    public function tellReportsCurrentPosition(): void
    {
        $reader = $this->createReader(chr(0x00) . chr(0x00) . chr(0x00));

        self::assertSame(0, $reader->tell());
        $reader->readU8();
        self::assertSame(1, $reader->tell());
        $reader->readU16BE();
        self::assertSame(3, $reader->tell());
    }

    /**
     * Seeks to absolute offsets and reads the expected byte.
     * This verifies that seeking resets the cursor before subsequent reads.
     */
    #[Test]
    public function seekChangesPosition(): void
    {
        $reader = $this->createReader(chr(0xAA) . chr(0xBB) . chr(0xCC));

        $reader->seek(1);
        self::assertSame(0xBB, $reader->readU8());

        $reader->seek(0);
        self::assertSame(0xAA, $reader->readU8());
    }
}
