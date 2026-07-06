<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Core\Traits\DelegatesToByteReader;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use const PHP_INT_MAX;
use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Represents a bounded view into a parent stream with an independent cursor.
 */
final class StreamWindow implements BinaryReadAccessInterface
{
    use ReadsBinaryPrimitives;
    use NormalizesOffsets;
    use DelegatesToByteReader;

    private int $cursor = 0;

    private readonly ByteReader $byteReader;

    /**
     * Creates a window that restricts reads to a fixed region of the parent stream.
     */
    public function __construct(
        private readonly Stream $base,
        private readonly int $offset,
        private readonly int $length,
    ) {
        $this->byteReader = $this->createByteReader('window');
    }

    /**
     * Returns the number of bytes exposed through this window.
     */
    public function size(): int
    {
        return $this->length;
    }

    /**
     * Reads bytes from the bounded region and advances the cursor.
     */
    public function read(int|UInt64 $length): string
    {
        if ($this->isZeroLength($length)) {
            return '';
        }

        $len = $this->normalizeReadLength($length, 'window read length out of range');

        if ($len > PHP_INT_MAX - $this->cursor) {
            throw new BoundsError('window read offset overflow', 1060);
        }

        if (($this->cursor + $len) > $this->length) {
            throw new BoundsError('window read out of range', 1016);
        }

        $targetPos = $this->offset + $this->cursor;

        if ($this->base->tell() !== $targetPos) {
            $this->base->seek($targetPos);
        }

        $data = $this->base->read($len);
        $this->cursor += $len;

        return $data;
    }

    /**
     * Exposes the ByteReader instance for the shared primitive read trait.
     */
    protected function offsetLimit(): int
    {
        return $this->length;
    }

    /**
     * Returns the ByteReader that delegates primitive reads to this window.
     *
     * @return ByteReader Shared reader instance.
     */
    protected function byteReader(): ByteReader
    {
        return $this->byteReader;
    }

    /**
     * Returns the current read offset within the window.
     *
     * @return int Current cursor position.
     */
    protected function currentOffset(): int
    {
        return $this->cursor;
    }

    /**
     * Resolves and applies a seek operation within the window bounds.
     *
     * @param int|UInt64 $offset Offset to seek to.
     * @param int        $whence Seek origin constant.
     */
    protected function seekInternal(int|UInt64 $offset, int $whence): void
    {
        $target = match ($whence) {
            SEEK_SET => $this->normalizeAbsoluteOffset($offset, 'window seek out of range'),
            SEEK_CUR => $this->normalizeRelativeOffset($offset, $this->cursor, 'window seek out of range'),
            SEEK_END => $this->normalizeRelativeOffset($offset, $this->length, 'window seek out of range'),
            default  => throw new ParseError('window invalid seek whence: ' . $whence, 1017),
        };

        $this->cursor = $target;
    }
}
