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

/**
 * Represents a bounded view into a parent stream with an independent cursor.
 */
final class StreamWindow
{
    private int $cursor = 0;

    private readonly ByteReader $byteReader;

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
        $this->byteReader = new ByteReader(
            read: fn (int $length): string => $this->read($length),
            tell: fn (): int => $this->cursor,
            seek: function (int|UInt64 $offset): void {
                $this->seekInternal($offset);
            },
            context: 'window',
        );
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
        return $this->byteReader->tell();
    }

    /**
     * Repositions the window cursor to an absolute offset inside the window.
     *
     * @param int $pos Byte offset relative to the window start.
     */
    public function seek(int $pos): void
    {
        $this->byteReader->seek($pos);
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
        return $this->byteReader->readU8();
    }

    /**
     * Reads an unsigned 16-bit big-endian integer from the window.
     *
     * @return int
     */
    public function readU16BE(): int
    {
        return $this->byteReader->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit big-endian integer from the window.
     *
     * @return int
     */
    public function readU32BE(): int
    {
        return $this->byteReader->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit big-endian integer from the window.
     *
     * @return UInt64
     */
    public function readU64BE(): UInt64
    {
        return $this->byteReader->readU64BE();
    }

    private function seekInternal(int|UInt64 $pos): void
    {
        if ($pos instanceof UInt64) {
            $pos = $pos->toInt('window seek out of range');
        }

        if ($pos < 0 || $pos > $this->length) {
            throw new BoundsError('window seek out of range');
        }

        $this->cursor = $pos;
    }
}
