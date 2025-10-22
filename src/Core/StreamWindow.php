<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

/**
 * Bounded view into a Stream (independent cursor, enforced limits).
 */
final class StreamWindow
{
    private int $cursor = 0;

    public function __construct(
        private readonly Stream $base,
        private readonly int $offset,
        private readonly int $length
    ) {}

    public function size(): int { return $this->length; }
    public function tell(): int { return $this->cursor; }

    public function seek(int $pos): void
    {
        if ($pos < 0 || $pos > $this->length) {
            throw new BoundsError('window seek out of range');
        }
        $this->cursor = $pos;
    }

    public function read(int $len): string
    {
        if ($len < 0 || $this->cursor + $len > $this->length) {
            throw new BoundsError('window read out of range');
        }
        $this->base->seek($this->offset + $this->cursor);
        $data = $this->base->read($len);
        $this->cursor += $len;
        return $data;
    }

    public function readU8(): int     { return ord($this->read(1)); }
    public function readU16BE(): int  { return unpack('n', $this->read(2))[1]; }
    public function readU32BE(): int  { return unpack('N', $this->read(4))[1]; }
    public function readU64BE(): int
    {
        $hi = $this->readU32BE();
        $lo = $this->readU32BE();
        return ($hi << 32) | $lo;
    }
}
