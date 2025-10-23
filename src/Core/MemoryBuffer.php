<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use function ord;
use function strlen;
use function unpack;

/**
 * Provides a simple, bounds-checked in-memory buffer abstraction.
 *
 * The buffer exposes random-access read operations that mimic the stream API
 * used throughout the project, ensuring that parser code can rely on
 * consistent guard rails even when the input data originates from a string.
 */
final class MemoryBuffer
{
    /**
     * @param string $data raw binary payload to expose as a seekable buffer
     * @param int    $pos  initial read position within the buffer
     */
    public function __construct(
        private readonly string $data,
        private int $pos = 0,
    ) {
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
        return $this->pos;
    }

    /**
     * Moves the read cursor to an absolute offset within the buffer.
     *
     * @param int $offset absolute position in bytes
     *
     * @throws BoundsError when the requested offset is outside the buffer
     */
    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size()) {
            throw new BoundsError("MemoryBuffer seek out of range: $offset");
        }
        $this->pos = $offset;
    }

    /**
     * Reads a fixed-length chunk from the current position.
     *
     * The cursor advances by the number of requested bytes.
     *
     * @param int $length number of bytes to consume
     *
     * @return string raw binary data with the requested length
     *
     * @throws BoundsError when the requested length exceeds the buffer
     * @throws ParseError  when the read returns fewer bytes than requested
     */
    public function read(int $length): string
    {
        $end = $this->pos + $length;
        if ($length < 0 || $end > $this->size()) {
            throw new BoundsError('MemoryBuffer read out of range: ' . $this->pos . '+' . $length);
        }
        $chunk = substr($this->data, $this->pos, $length);
        if (strlen($chunk) !== $length) {
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
        return ord($this->read(1));
    }

    /**
     * Reads an unsigned 16-bit integer using little-endian byte order.
     *
     * @return int unsigned 16-bit integer
     */
    public function readU16LE(): int
    {
        return unpack('v', $this->read(2))[1];
    }

    /**
     * Reads an unsigned 16-bit integer using big-endian byte order.
     *
     * @return int unsigned 16-bit integer
     */
    public function readU16BE(): int
    {
        return unpack('n', $this->read(2))[1];
    }

    /**
     * Reads an unsigned 32-bit integer using little-endian byte order.
     *
     * @return int unsigned 32-bit integer
     */
    public function readU32LE(): int
    {
        return unpack('V', $this->read(4))[1];
    }

    /**
     * Reads an unsigned 32-bit integer using big-endian byte order.
     *
     * @return int unsigned 32-bit integer
     */
    public function readU32BE(): int
    {
        return unpack('N', $this->read(4))[1];
    }

    /**
     * Reads an unsigned 64-bit integer using little-endian byte order.
     *
     * @return int unsigned 64-bit integer
     */
    public function readU64LE(): int
    {
        $lo = $this->readU32LE();
        $hi = $this->readU32LE();

        return ($hi << 32) | $lo;
    }

    /**
     * Reads an unsigned 64-bit integer using big-endian byte order.
     *
     * @return int unsigned 64-bit integer
     */
    public function readU64BE(): int
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();

        return ($hi << 32) | $lo;
    }
}
