<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Traits;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use const SEEK_SET;

/**
 * Wires a ByteReader over a cursor-backed source and delegates the public cursor
 * operations to it.
 *
 * Every BinaryReadAccessInterface implementation that is backed by a ByteReader
 * shares the same wiring: it builds the reader from its own chunk reader, cursor
 * position and seek routine, then forwards tell()/seek() to them. This trait
 * removes that boilerplate; the consuming class only supplies the three source
 * primitives through the abstract hooks below.
 */
trait DelegatesToByteReader
{
    /**
     * Returns the byte reader used to access the underlying data source.
     *
     * @return ByteReader Shared reader instance.
     */
    abstract protected function byteReader(): ByteReader;

    /**
     * Returns the current read offset within the source.
     *
     * @return int Current cursor position.
     */
    abstract protected function currentOffset(): int;

    /**
     * Applies a seek operation within the source bounds.
     *
     * @param int|UInt64 $offset Offset to seek to.
     * @param int        $whence Seek origin constant.
     */
    abstract protected function seekInternal(int|UInt64 $offset, int $whence): void;

    /**
     * Reads a fixed-length chunk from the current position.
     *
     * @param int|UInt64 $length Number of bytes to consume.
     *
     * @return string Raw binary data with the requested length.
     */
    abstract public function read(int|UInt64 $length): string;

    /**
     * Builds a ByteReader that delegates its primitive operations back to the source.
     *
     * @param string $context Label used in ByteReader diagnostics.
     *
     * @return ByteReader Reader bound to this source.
     */
    protected function createByteReader(string $context): ByteReader
    {
        return new ByteReader(
            read: $this->read(...),
            tell: fn (): int => $this->currentOffset(),
            seek: function (int|UInt64 $offset, int $whence): void {
                $this->seekInternal($offset, $whence);
            },
            context: $context,
        );
    }

    /**
     * Reports the current read offset within the source.
     *
     * @return int Current cursor position.
     */
    public function tell(): int
    {
        return $this->byteReader()->tell();
    }

    /**
     * Moves the read cursor to an absolute offset within the source.
     *
     * @param int|UInt64 $offset Absolute position in bytes.
     * @param int        $whence Seek origin constant.
     */
    public function seek(int|UInt64 $offset, int $whence = SEEK_SET): void
    {
        $this->seekInternal($offset, $whence);
    }
}
