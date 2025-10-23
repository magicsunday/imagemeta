<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use function fopen;
use function fread;
use function fseek;
use function fstat;
use function is_array;
use function is_float;
use function is_int;
use function ord;
use function strlen;
use function unpack;

/**
 * Provides a bounds-checked streaming reader over a binary resource handle.
 */
final class Stream
{
    /** @var resource */
    private $fh;
    private int $size;
    private int $pos = 0;

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
            throw new ParseError("Cannot open: $path");
        }
        $stat = fstat($fh);
        if (!is_array($stat)) {
            throw new ParseError("Cannot determine size of: $path");
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
        $this->fh   = $fh;
        $this->size = $size;
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
        return $this->pos;
    }

    /**
     * Moves the read cursor to an absolute offset within the stream.
     *
     * @param int $offset Absolute zero-based byte offset to seek to.
     */
    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size) {
            throw new BoundsError("seek out of range: $offset");
        }
        fseek($this->fh, $offset);
        $this->pos = $offset;
    }

    /**
     * Reads a fixed number of bytes from the stream, advancing the cursor.
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

        if ($len < 0 || $this->pos + $len > $this->size) {
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
     * Reads a single unsigned byte from the stream.
     *
     * @return int
     */
    public function readU8(): int
    {
        return ord($this->read(1));
    }

    /**
     * Reads an unsigned 16-bit big-endian integer from the stream.
     *
     * @return int
     */
    public function readU16BE(): int
    {
        return $this->unpackInt('n', 2);
    }

    /**
     * Reads an unsigned 32-bit big-endian integer from the stream.
     *
     * @return int
     */
    public function readU32BE(): int
    {
        return $this->unpackInt('N', 4);
    }

    /**
     * Reads an unsigned 64-bit big-endian integer from the stream.
     *
     * @return int
     */
    public function readU64BE(): int
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();

        return ($hi << 32) | $lo;
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
     * Reads the requested number of bytes and unpacks the first value using the provided format.
     *
     * @param string $format Format string understood by {@see unpack}.
     * @param int    $length Number of bytes to consume from the stream.
     *
     * @return int
     */
    private function unpackInt(string $format, int $length): int
    {
        $bytes  = $this->read($length);
        $result = unpack($format, $bytes);

        if ($result === false || !isset($result[1])) {
            throw new ParseError('Failed to unpack integer from stream.');
        }
        $value = $result[1];
        if (!is_int($value) && !is_float($value)) {
            throw new ParseError('Unpack returned a non-numeric value.');
        }

        return (int) $value;
    }
}
