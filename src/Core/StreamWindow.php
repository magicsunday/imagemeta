<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Core\Contracts\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Represents a bounded view into a parent stream with an independent cursor.
 */
final class StreamWindow implements BinaryReadAccessInterface
{
    use ReadsBinaryPrimitives;

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
        $this->byteReader = new ByteReader(
            read: $this->read(...),
            tell: fn (): int => $this->cursor,
            seek: function (int|UInt64 $offset, int $whence): void {
                $this->seekInternal($offset, $whence);
            },
            context: 'window',
        );
    }

    /**
     * Returns the number of bytes exposed through this window.
     */
    public function size(): int
    {
        return $this->length;
    }

    /**
     * Returns the cursor position relative to the start of the window.
     */
    public function tell(): int
    {
        return $this->byteReader->getPosition();
    }

    /**
     * Repositions the window cursor to an absolute offset inside the window.
     */
    public function seek(int|UInt64 $offset, int $whence = SEEK_SET): void
    {
        $this->seekInternal($offset, $whence);
    }

    /**
     * Reads bytes from the bounded region and advances the cursor.
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

        if ($this->cursor + $len > $this->length) {
            throw new BoundsError('window read out of range');
        }

        $this->base->seek($this->offset + $this->cursor, SEEK_SET);
        $data = $this->base->read($len);
        $this->cursor += $len;

        return $data;
    }

    /**
     * Exposes the ByteReader instance for the shared primitive read trait.
     */
    protected function byteReader(): ByteReader
    {
        return $this->byteReader;
    }

    private function seekInternal(int|UInt64 $offset, int $whence): void
    {
        $target = match ($whence) {
            SEEK_SET => $this->normaliseAbsoluteOffset($offset, 'window seek out of range'),
            SEEK_CUR => $this->normaliseRelativeOffset($offset, $this->cursor, 'window seek out of range'),
            SEEK_END => $this->normaliseRelativeOffset($offset, $this->length, 'window seek out of range'),
            default  => throw new ParseError('window invalid seek whence: ' . $whence),
        };

        $this->cursor = $target;
    }

    /**
     * @return positive-int
     */
    private function normaliseLength(int|UInt64 $length): int
    {
        if ($length instanceof UInt64) {
            if ($length->isZero()) {
                throw new BoundsError('window read length out of range: ' . $length->toHex());
            }

            $length = $length->toInt('window read length out of range');
        }

        if ($length <= 0) {
            throw new BoundsError('window read length out of range: ' . $length);
        }

        return $length;
    }

    private function normaliseAbsoluteOffset(int|UInt64 $offset, string $message): int
    {
        if ($offset instanceof UInt64) {
            if ($offset->compareInt($this->length) > 0) {
                throw new BoundsError($message . ': ' . $offset->toHex());
            }

            $offset = $offset->toInt($message);
        }

        if ($offset < 0 || $offset > $this->length) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset));
        }

        return $offset;
    }

    private function normaliseRelativeOffset(int|UInt64 $offset, int $base, string $message): int
    {
        $delta  = $this->resolveOffsetValue($offset, $message);
        $target = $base + $delta;

        if ($target < 0 || $target > $this->length) {
            throw new BoundsError($message . ': ' . $this->formatOffset($offset));
        }

        return $target;
    }

    private function resolveOffsetValue(int|UInt64 $offset, string $message): int
    {
        if ($offset instanceof UInt64) {
            return $offset->toInt($message);
        }

        return $offset;
    }

    private function formatOffset(int|UInt64 $offset): string
    {
        return $offset instanceof UInt64 ? $offset->toHex() : (string) $offset;
    }
}
