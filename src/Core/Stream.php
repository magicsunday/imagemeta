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

use function fopen;
use function fread;
use function fseek;
use function fstat;
use function is_array;
use function strlen;

/**
 * Provides a bounds-checked streaming reader over a binary resource handle.
 */
final class Stream
{
    /** @var resource */
    private $fh;

    private readonly int $size;

    private int $pos = 0;

    private readonly ByteReader $byteReader;

    /**
     * Opens the given path for binary reading and wraps it in a stream instance.
     *
     * @param string $path Absolute or relative file system path to open.
     *
     * @return self
     */
    public static function fromPath(string $path): self
    {
        $fh = fopen($path, 'rb');
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
    public function __construct($fh, int $size)
    {
        $this->fh         = $fh;
        $this->size       = $size;
        $this->byteReader = new ByteReader(
            read: fn (int $length): string => $this->read($length),
            tell: fn (): int => $this->pos,
            seek: function (int|UInt64 $offset): void {
                $this->seekInternal($offset);
            },
            context: 'stream',
        );
    }

    /**
     * Returns the total size of the underlying data source in bytes.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Returns the current cursor position relative to the start of the stream.
     *
     * @return int
     */
    public function tell(): int
    {
        return $this->byteReader->tell();
    }

    /**
     * Moves the read cursor to an absolute offset within the stream.
     *
     * @param int $offset Absolute zero-based byte offset to seek to.
     */
    public function seek(int $offset): void
    {
        $this->byteReader->seek($offset);
    }

    /**
     * Reads a fixed number of bytes from the stream, advancing the cursor.
     *
     * @param int $length Number of bytes to read.
     *
     * @return string
     */
    public function read(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        if ($length < 0 || $this->pos + $length > $this->size) {
            throw new BoundsError('read beyond EOF: ' . $this->pos . '+' . $length . ' > ' . $this->size);
        }

        $data = fread($this->fh, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new ParseError('short read');
        }

        $this->pos += $length;

        return $data;
    }

    /**
     * Reads a single unsigned byte from the stream.
     *
     * @return int
     */
    public function readU8(): int
    {
        return $this->byteReader->readU8();
    }

    /**
     * Reads an unsigned 16-bit big-endian integer from the stream.
     *
     * @return int
     */
    public function readU16BE(): int
    {
        return $this->byteReader->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit big-endian integer from the stream.
     *
     * @return int
     */
    public function readU32BE(): int
    {
        return $this->byteReader->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit big-endian integer from the stream.
     *
     * @return UInt64
     */
    public function readU64BE(): UInt64
    {
        return $this->byteReader->readU64BE();
    }

    /**
     * Creates a bounded view into this stream without copying bytes.
     *
     * @param int $offset Starting byte offset for the window.
     * @param int $length Maximum number of bytes readable from the window.
     */
    public function window(int $offset, int $length): StreamWindow
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->size) {
            throw new BoundsError('window out of range');
        }

        return new StreamWindow($this, $offset, $length);
    }

    /**
     * Validates and seeks the underlying resource to an absolute offset.
     *
     * @param int|UInt64 $offset Absolute byte offset relative to the start of the stream.
     *
     * @throws BoundsError If the offset is negative or exceeds the stream size.
     * @throws ParseError  If the offset cannot be converted to a supported integer range.
     */
    private function seekInternal(int|UInt64 $offset): void
    {
        if ($offset instanceof UInt64) {
            if ($offset->compareInt($this->size) > 0) {
                throw new BoundsError('seek out of range: ' . $offset->toHex());
            }

            $offsetValue  = $offset->toInt('seek out of range');
            $messageValue = $offset->toHex();
        } else {
            $offsetValue  = $offset;
            $messageValue = (string) $offset;
        }

        if ($offsetValue < 0 || $offsetValue > $this->size) {
            throw new BoundsError('seek out of range: ' . $messageValue);
        }

        fseek($this->fh, $offsetValue);
        $this->pos = $offsetValue;
    }
}
