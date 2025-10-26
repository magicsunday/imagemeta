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

use function ord;

/**
 * Represents a bounded view into a parent stream with an independent cursor.
 */
final class StreamWindow
{
    private int $cursor = 0;

    /**
     * Creates a window that restricts reads to a fixed region of the parent stream.
     *
     * @param Stream $base   Underlying stream providing the bytes.
     * @param int    $offset Start offset within the base stream.
     * @param int    $length Maximum number of bytes accessible through the window.
     */
    public function __construct(
        private readonly Stream $base,
        private readonly int $offset,
        private readonly int $length,
    ) {
    }

    /**
     * Returns the number of bytes exposed through this window.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->length;
    }

    /**
     * Returns the cursor position relative to the start of the window.
     *
     * @return int
     */
    public function tell(): int
    {
        return $this->cursor;
    }

    /**
     * Repositions the window cursor to an absolute offset inside the window.
     *
     * @param int $pos Byte offset relative to the window start.
     */
    public function seek(int $pos): void
    {
        if ($pos < 0 || $pos > $this->length) {
            throw new BoundsError('window seek out of range');
        }

        $this->cursor = $pos;
    }

    /**
     * Reads bytes from the bounded region and advances the cursor.
     *
     * @param int $len Number of bytes to read.
     *
     * @return string
     */
    public function read(int $len): string
    {
        if ($len === 0) {
            return '';
        }

        if ($len < 0 || $this->cursor + $len > $this->length) {
            throw new BoundsError('window read out of range');
        }

        $this->base->seek($this->offset + $this->cursor);
        $data = $this->base->read($len);
        $this->cursor += $len;

        return $data;
    }

    /**
     * Reads an unsigned byte from the window.
     *
     * @return int
     */
    public function readU8(): int
    {
        return ord($this->read(1));
    }

    /**
     * Reads an unsigned 16-bit big-endian integer from the window.
     *
     * @return int
     */
    public function readU16BE(): int
    {
        return $this->unpackInt('n', 2);
    }

    /**
     * Reads an unsigned 32-bit big-endian integer from the window.
     *
     * @return int
     */
    public function readU32BE(): int
    {
        return $this->unpackInt('N', 4);
    }

    /**
     * Reads an unsigned 64-bit big-endian integer from the window.
     *
     * @return UInt64
     */
    public function readU64BE(): UInt64
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();

        return Unpack::combineUint32($hi, $lo);
    }

    /**
     * Reads a fixed number of bytes from the window and unpacks the first value using the given format.
     *
     * @param string $format Format string understood by {@see Unpack::int}.
     * @param int    $length Number of bytes to read before unpacking.
     *
     * @return int
     */
    private function unpackInt(string $format, int $length): int
    {
        $bytes = $this->read($length);

        return Unpack::int($format, $bytes, 'integer from window');
    }
}
