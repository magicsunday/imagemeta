<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contracts;

use MagicSunday\ImageMeta\Core\Util\UInt64;

use const SEEK_SET;

/**
 * Defines a unified contract for binary data sources that support random access reads.
 */
interface BinaryReadAccessInterface
{
    /**
     * Returns the total number of bytes available from the data source.
     */
    public function size(): int;

    /**
     * Returns the current zero-based cursor position.
     */
    public function tell(): int;

    /**
     * Moves the cursor relative to the provided whence origin.
     *
     * @param int|UInt64 $offset Byte offset relative to the origin specified by $whence.
     * @param int        $whence One of the PHP SEEK_* constants describing the origin.
     */
    public function seek(int|UInt64 $offset, int $whence = SEEK_SET): void;

    /**
     * Reads a fixed number of bytes from the current cursor position.
     *
     * @param int|UInt64 $length Number of bytes to read.
     */
    public function read(int|UInt64 $length): string;

    /**
     * Reads an unsigned 8-bit integer from the data source.
     */
    public function readU8(): int;

    /**
     * Reads an unsigned 16-bit big-endian integer from the data source.
     */
    public function readU16BE(): int;

    /**
     * Reads an unsigned 32-bit big-endian integer from the data source.
     */
    public function readU32BE(): int;

    /**
     * Reads an unsigned 64-bit big-endian integer from the data source.
     */
    public function readU64BE(): UInt64;
}
