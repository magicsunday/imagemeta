<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use function strlen;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Provides a simple, bounds-checked in-memory buffer abstraction.
 *
 * The buffer exposes random-access read operations that mimic the stream API
 * used throughout the project, ensuring that parser code can rely on
 * consistent guard rails even when the input data originates from a string.
 */
final class MemoryBuffer implements BinaryReadAccessInterface
{
    use ReadsBinaryPrimitives;
    use NormalisesOffsets;

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
            seek: function (int|UInt64 $offset, int $whence): void {
                $this->seekInternal($offset, $whence);
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
    public function seek(int|UInt64 $offset, int $whence = SEEK_SET): void
    {
        $this->seekInternal($offset, $whence);
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
        if ($length instanceof UInt64) {
            if ($length->isZero()) {
                return '';
            }
        } elseif ($length === 0) {
            return '';
        }

        $len = $this->normaliseLength($length);
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
     * Reads an unsigned 16-bit integer using little-endian byte order.
     *
     * @return int unsigned 16-bit integer
     */
    public function readU16LE(): int
    {
        return $this->byteReader->readU16LE();
    }

    /**
     * Reads an unsigned 32-bit integer using little-endian byte order.
     *
     * @return int unsigned 32-bit integer
     */
    public function readU32LE(): int
    {
        return $this->byteReader->readU32LE();
    }

    /**
     * Reads an unsigned 64-bit integer using little-endian byte order.
     *
     * @return UInt64 unsigned 64-bit integer
     */
    public function readU64LE(): UInt64
    {
        return $this->byteReader->readU64LE();
    }

    /**
     * Exposes the ByteReader instance for the shared primitive read trait.
     */
    protected function offsetLimit(): int
    {
        return $this->size();
    }

    /**
     * Returns the ByteReader that delegates primitive reads to this buffer.
     *
     * @return ByteReader Shared reader instance.
     */
    protected function byteReader(): ByteReader
    {
        return $this->byteReader;
    }

    /**
     * Applies a seek operation within the buffer bounds.
     *
     * @param int|UInt64 $offset Offset to seek to.
     * @param int        $whence Seek origin constant.
     *
     * @return void
     */
    private function seekInternal(int|UInt64 $offset, int $whence): void
    {
        $target = match ($whence) {
            SEEK_SET => $this->normaliseOffset($offset, 0, 'MemoryBuffer seek out of range'),
            SEEK_CUR => $this->normaliseRelativeOffset($offset, $this->pos, 'MemoryBuffer seek out of range'),
            SEEK_END => $this->normaliseRelativeOffset($offset, $this->size(), 'MemoryBuffer seek out of range'),
            default  => throw new ParseError('MemoryBuffer invalid seek whence: ' . $whence),
        };

        $this->pos = $target;
    }

    /**
     * Normalises an absolute offset and validates it against the buffer size.
     *
     * @param int|UInt64 $offset  Offset to validate.
     * @param int        $length  Required byte count from the offset.
     * @param string     $message Error context for bounds violations.
     *
     * @return int Validated absolute offset.
     *
     * @throws BoundsError When the offset would exceed buffer bounds.
     */
    private function normaliseOffset(int|UInt64 $offset, int $length, string $message): int
    {
        if ($offset instanceof UInt64) {
            return $this->normaliseUInt64($offset, $length, $message);
        }

        if ($offset < 0) {
            throw new BoundsError($message . ': ' . $offset);
        }

        if (($length > $this->size()) || ($offset > ($this->size() - $length))) {
            throw new BoundsError($message . ': ' . $offset);
        }

        return $offset;
    }

    /**
     * Normalizes a length value to a positive integer with bounds checking.
     *
     * @param int|UInt64 $length Length value to normalize.
     *
     * @return positive-int Validated positive integer length.
     *
     * @throws BoundsError If the length is zero, negative, or exceeds bounds.
     */
    private function normaliseLength(int|UInt64 $length): int
    {
        if ($length instanceof UInt64) {
            if ($length->isZero()) {
                throw new BoundsError('MemoryBuffer read length out of range: ' . $length->toHex());
            }

            $intValue = $this->normaliseUInt64($length, 0, 'MemoryBuffer read length out of range');
            if ($intValue <= 0) {
                throw new BoundsError('MemoryBuffer read length out of range: ' . $length->toHex());
            }

            return $intValue;
        }

        if ($length <= 0) {
            throw new BoundsError('MemoryBuffer read length out of range: ' . $length);
        }

        return $length;
    }

    /**
     * Converts a UInt64 offset into a bounded integer.
     *
     * @param UInt64 $value   Offset to validate.
     * @param int    $padding Minimum remaining space required from the offset.
     * @param string $message Error context for bounds violations.
     *
     * @return int Validated absolute offset.
     *
     * @throws BoundsError When the offset would exceed buffer bounds.
     */
    private function normaliseUInt64(UInt64 $value, int $padding, string $message): int
    {
        if ($value->compareInt($this->size()) > 0) {
            throw new BoundsError($message . ': ' . $value->toHex());
        }

        $intValue = $value->toInt($message);

        if ($intValue > ($this->size() - $padding)) {
            throw new BoundsError($message . ': ' . $value->toHex());
        }

        return $intValue;
    }
}
