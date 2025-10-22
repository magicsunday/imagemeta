<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

final class MemoryBuffer
{
    public function __construct(
        private readonly string $data,
        private int $pos = 0
    ) {}

    public function size(): int { return strlen($this->data); }
    public function tell(): int { return $this->pos; }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size()) {
            throw new BoundsError("MemoryBuffer seek out of range: {$offset}");
        }
        $this->pos = $offset;
    }

    public function read(int $len): string
    {
        $end = $this->pos + $len;
        if ($len < 0 || $end > $this->size()) {
            throw new BoundsError("MemoryBuffer read out of range: {$this->pos}+{$len}");
        }
        $chunk = substr($this->data, $this->pos, $len);
        if (strlen($chunk) !== $len) {
            throw new ParseError('MemoryBuffer short read');
        }
        $this->pos = $end;
        return $chunk;
    }

    public function readU8(): int     { return ord($this->read(1)); }
    public function readU16LE(): int  { return unpack('v', $this->read(2))[1]; }
    public function readU16BE(): int  { return unpack('n', $this->read(2))[1]; }
    public function readU32LE(): int  { return unpack('V', $this->read(4))[1]; }
    public function readU32BE(): int  { return unpack('N', $this->read(4))[1]; }

    public function readU64LE(): int
    {
        $lo = $this->readU32LE();
        $hi = $this->readU32LE();
        return ($hi << 32) | $lo;
    }
    public function readU64BE(): int
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();
        return ($hi << 32) | $lo;
    }
}
