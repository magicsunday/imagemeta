<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

/**
 * Streaming, bounds-checked reader over a file handle.
 */
final class Stream
{
    /** @var resource */
    private $fh;
    private int $size;
    private int $pos = 0;

    public static function fromPath(string $path): self
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new ParseError("Cannot open: $path");
        }
        $stat = fstat($fh);
        $size = (int)($stat['size'] ?? 0);
        return new self($fh, $size);
    }

    /**
     * @param resource $fh
     */
    public function __construct($fh, int $size)
    {
        $this->fh = $fh;
        $this->size = $size;
    }

    public function size(): int { return $this->size; }
    public function tell(): int { return $this->pos; }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size) {
            throw new BoundsError("seek out of range: $offset");
        }
        fseek($this->fh, $offset);
        $this->pos = $offset;
    }

    public function read(int $len): string
    {
        if ($len < 0 || $this->pos + $len > $this->size) {
            throw new BoundsError("read beyond EOF: {$this->pos}+{$len} > {$this->size}");
        }
        $data = fread($this->fh, $len);
        if ($data === false || strlen($data) !== $len) {
            throw new ParseError('short read');
        }
        $this->pos += $len;
        return $data;
    }

    public function readU8(): int   { return ord($this->read(1)); }
    public function readU16BE(): int { return unpack('n', $this->read(2))[1]; }
    public function readU32BE(): int { return unpack('N', $this->read(4))[1]; }
    public function readU64BE(): int
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();
        return ($hi << 32) | $lo;
    }

    /** Create a bounded view into this stream without copying bytes. */
    public function window(int $offset, int $length): StreamWindow
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->size) {
            throw new BoundsError('window out of range');
        }
        return new StreamWindow($this, $offset, $length);
    }
}
