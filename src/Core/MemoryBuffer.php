<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function strlen;

/**
 * Provides a simple, bounds-checked in-memory buffer abstraction.
 *
 * The buffer exposes random-access read operations that mimic the stream API
 * used throughout the project, ensuring that parser code can rely on
 * consistent guard rails even when the input data originates from a string.
 */
final class MemoryBuffer
{
    private readonly ByteReader $byteReader;

    /**
     * @param string $data raw binary payload to expose as a seekable buffer
     * @param int    $pos  initial read position within the buffer
     */
    public function __construct(
        private readonly string $data,
        private int $pos = 0,
    ) {
        $this->byteReader = new ByteReader(
            read: $this->read(...),
            tell: fn (): int => $this->pos,
            seek: function (int|UInt64 $offset): void {
                $this->seekInternal($offset);
            },
            context: 'buffer',
        );
    }

    /**
     * Returns the total size of the backing buffer in bytes.
     *
     * @return int number of available bytes
     */
    public function size(): int
    {
        return strlen($this->data);
    }

    /**
     * Reports the current read offset within the buffer.
     *
     * @return int current cursor position
     */
    public function tell(): int
    {
        return $this->byteReader->getPosition();
    }

    /**
     * Moves the read cursor to an absolute offset within the buffer.
     *
     * @param int|UInt64 $offset absolute position in bytes
     *
     * @throws BoundsError when the requested offset is outside the buffer
     */
    public function seek(int|UInt64 $offset): void
    {
        $this->byteReader->setPosition($offset);
    }

    /**
     * Reads a fixed-length chunk from the current position.
     *
     * The cursor advances by the number of requested bytes.
     *
     * @param int|UInt64 $length number of bytes to consume
     *
     * @return string raw binary data with the requested length
     *
     * @throws BoundsError when the requested length exceeds the buffer
     * @throws ParseError  when the read returns fewer bytes than requested
     */
    public function read(int|UInt64 $length): string
    {
        $len = $this->normaliseLength($length);

        if ($len === 0) {
            return '';
        }

        $end = $this->pos + $len;
        if ($end > $this->size()) {
            throw new BoundsError('MemoryBuffer read out of range: ' . $this->pos . '+' . $len);
        }

        $chunk = substr($this->data, $this->pos, $len);
        if (strlen($chunk) !== $len) {
            throw new ParseError('MemoryBuffer short read');
        }

        $this->pos = $end;

        return $chunk;
    }

    /**
     * Reads an unsigned 8-bit integer from the buffer.
     *
     * @return int unsigned 8-bit integer
     */
    public function readU8(): int
    {
        return $this->byteReader->readU8();
    }

    /**
     * Reads an unsigned 16-bit integer using little-endian byte order.
     *
     * @return int unsigned 16-bit integer
     */
    public function readU16LE(): int
    {
        return $this->byteReader->unpackInt('v', 2);
    }

    /**
     * Reads an unsigned 16-bit integer using big-endian byte order.
     *
     * @return int unsigned 16-bit integer
     */
    public function readU16BE(): int
    {
        return $this->byteReader->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit integer using little-endian byte order.
     *
     * @return int unsigned 32-bit integer
     */
    public function readU32LE(): int
    {
        return $this->byteReader->unpackInt('V', 4);
    }

    /**
     * Reads an unsigned 32-bit integer using big-endian byte order.
     *
     * @return int unsigned 32-bit integer
     */
    public function readU32BE(): int
    {
        return $this->byteReader->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit integer using little-endian byte order.
     *
     * @return UInt64 unsigned 64-bit integer
     */
    public function readU64LE(): UInt64
    {
        $lo = $this->readU32LE();
        $hi = $this->readU32LE();

        return Unpack::combineUint32($hi, $lo);
    }

    /**
     * Reads an unsigned 64-bit integer using big-endian byte order.
     *
     * @return UInt64 unsigned 64-bit integer
     */
    public function readU64BE(): UInt64
    {
        return $this->byteReader->readU64BE();
    }

    private function seekInternal(int|UInt64 $offset): void
    {
        $this->pos = $this->normaliseOffset($offset, 0, 'MemoryBuffer seek out of range');
    }

    private function normaliseOffset(int|UInt64 $offset, int $length, string $message): int
    {
        if ($offset instanceof UInt64) {
            return $this->normaliseUInt64($offset, $length, $message);
        }

        if ($offset < 0) {
            throw new BoundsError($message . ': ' . $offset);
        }

        if ($offset > $this->size() - $length) {
            throw new BoundsError($message . ': ' . $offset);
        }

        return $offset;
    }

    private function normaliseLength(int|UInt64 $length): int
    {
        if ($length instanceof UInt64) {
            return $this->normaliseUInt64($length, 0, 'MemoryBuffer read length out of range');
        }

        if ($length < 0) {
            throw new BoundsError('MemoryBuffer read length out of range: ' . $length);
        }

        return $length;
    }

    private function normaliseUInt64(UInt64 $value, int $padding, string $message): int
    {
        if ($value->compareInt($this->size()) > 0) {
            throw new BoundsError($message . ': ' . $value->toHex());
        }

        $intValue = $value->toInt($message);

        if ($intValue > $this->size() - $padding) {
            throw new BoundsError($message . ': ' . $value->toHex());
        }

        return $intValue;
    }
}
