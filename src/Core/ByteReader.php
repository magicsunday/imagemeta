<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use Closure;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function ord;

use const SEEK_SET;

/**
 * Provides shared unsigned integer read helpers for byte-oriented data sources.
 */
final readonly class ByteReader
{
    private Closure $read;

    private Closure $tell;

    private Closure $seek;

    /**
     * Creates a reader backed by callbacks that operate on an external data source.
     *
     * @param Closure(int):string           $read    Callback that returns the requested number of bytes.
     * @param Closure():int                 $tell    Callback that reports the current cursor position.
     * @param Closure(int|UInt64, int):void $seek    Callback that repositions the cursor of the data source.
     * @param string                        $context Short description used in error messages.
     */
    public function __construct(
        Closure $read,
        Closure $tell,
        Closure $seek,
        private string $context,
    ) {
        $this->read = $read;
        $this->tell = $tell;
        $this->seek = $seek;
    }

    /**
     * Reads an unsigned 8-bit integer.
     */
    public function readU8(): int
    {
        $bytes = ($this->read)(1);

        return ord($bytes);
    }

    /**
     * Reads an unsigned 16-bit big-endian integer.
     */
    public function readU16BE(): int
    {
        return $this->unpackInt('n', 2);
    }

    /**
     * Reads an unsigned 32-bit big-endian integer.
     */
    public function readU32BE(): int
    {
        return $this->unpackInt('N', 4);
    }

    /**
     * Reads an unsigned 64-bit big-endian integer.
     */
    public function readU64BE(): UInt64
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();

        return Unpack::combineUint32($hi, $lo);
    }

    /**
     * Reads bytes and unpacks the first value according to the provided format.
     */
    public function unpackInt(string $format, int $length): int
    {
        $bytes = ($this->read)($length);

        return Unpack::int($format, $bytes, 'integer from ' . $this->context);
    }

    /**
     * Returns the current cursor position of the underlying data source.
     */
    public function getPosition(): int
    {
        return ($this->tell)();
    }

    /**
     * Moves the cursor of the underlying data source.
     */
    public function setPosition(int|UInt64 $offset, int $whence = SEEK_SET): void
    {
        ($this->seek)($offset, $whence);
    }
}
