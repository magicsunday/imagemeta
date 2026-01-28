<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use MagicSunday\ImageMeta\Contracts\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use function fopen;
use function fread;
use function fseek;
use function fstat;
use function is_array;
use function strlen;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Provides a bounds-checked streaming reader over a binary resource handle.
 */
final class Stream implements BinaryReadAccessInterface
{
    use ReadsBinaryPrimitives;
    use NormalisesOffsets;

    private int $pos = 0;

    private readonly ByteReader $byteReader;

    /**
     * Opens the given path for binary reading and wraps it in a stream instance.
     *
     * @param string $path Absolute or relative file system path to open.
     */
    public static function fromPath(string $path): self
    {
        $fh = @fopen($path, 'rb');

        if ($fh === false) {
            throw new ParseError('Cannot open: ' . $path);
        }

        $stat = fstat($fh);

        if (!is_array($stat)) {
            throw new ParseError('Cannot determine size of: ' . $path);
        }

        $size = $stat['size'];

        return new self($fh, $size);
    }

    /**
     * Creates the stream around an existing resource and file size information.
     *
     * @param resource $fh   Open resource positioned at the beginning of the file.
     * @param int      $size Total size of the readable data in bytes.
     */
    public function __construct(private $fh, private readonly int $size)
    {
        $this->byteReader = new ByteReader(
            read: $this->read(...),
            tell: fn (): int => $this->pos,
            seek: function (int|UInt64 $offset, int $whence): void {
                $this->seekInternal($offset, $whence);
            },
            context: 'stream',
        );
    }

    /**
     * Returns the total size of the underlying data source in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Returns the current cursor position relative to the start of the stream.
     */
    public function tell(): int
    {
        return $this->byteReader->getPosition();
    }

    /**
     * Moves the read cursor to an absolute offset within the stream.
     */
    public function seek(int|UInt64 $offset, int $whence = SEEK_SET): void
    {
        $this->seekInternal($offset, $whence);
    }

    /**
     * Reads a fixed number of bytes from the stream, advancing the cursor.
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

        $len = $this->normaliseReadLength($length, 'stream read length out of range');

        if (($this->pos + $len) > $this->size) {
            throw new BoundsError('read beyond EOF: ' . $this->pos . '+' . $len . ' > ' . $this->size);
        }

        $data = fread($this->fh, $len);
        if ($data === false || strlen($data) !== $len) {
            throw new ParseError('short read');
        }

        $this->pos += $len;

        return $data;
    }

    /**
     * Creates a bounded view into this stream without copying bytes.
     */
    public function window(int $offset, int $length): StreamWindow
    {
        if (($offset < 0) || ($length < 0) || (($offset + $length) > $this->size)) {
            throw new BoundsError('window out of range');
        }

        return new StreamWindow($this, $offset, $length);
    }

    /**
     * Exposes the ByteReader instance for the shared primitive read trait.
     */
    protected function offsetLimit(): int
    {
        return $this->size;
    }

    protected function byteReader(): ByteReader
    {
        return $this->byteReader;
    }

    private function seekInternal(int|UInt64 $offset, int $whence): void
    {
        $target = match ($whence) {
            SEEK_SET => $this->normaliseAbsoluteOffset($offset, 'seek out of range'),
            SEEK_CUR => $this->normaliseRelativeOffset($offset, $this->pos, 'seek out of range'),
            SEEK_END => $this->normaliseRelativeOffset($offset, $this->size, 'seek out of range'),
            default  => throw new ParseError('invalid seek whence: ' . $whence),
        };

        fseek($this->fh, $target);
        $this->pos = $target;
    }
}
