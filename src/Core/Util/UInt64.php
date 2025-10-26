<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use MagicSunday\ImageMeta\Core\ParseError;

use function intdiv;
use function sprintf;

/**
 * Represents an unsigned 64-bit integer composed of two 32-bit halves.
 */
final class UInt64
{
    private const int UINT32_MASK = 0xFFFFFFFF;

    private const int UINT32_BASE = 4_294_967_296;

    public function __construct(
        private readonly int $hi,
        private readonly int $lo,
    ) {
        $this->assertUint32($this->hi);
        $this->assertUint32($this->lo);
    }

    /**
     * Creates an instance from two unsigned 32-bit components.
     */
    public static function fromUInt32(int $hi, int $lo): self
    {
        return new self($hi & self::UINT32_MASK, $lo & self::UINT32_MASK);
    }

    /**
     * Creates an instance from a non-negative integer value.
     */
    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new ParseError('Cannot create UInt64 from a negative integer.');
        }

        $hi = (int) intdiv($value, self::UINT32_BASE);
        $lo = $value & self::UINT32_MASK;

        return new self($hi, $lo);
    }

    public function high(): int
    {
        return $this->hi;
    }

    public function low(): int
    {
        return $this->lo;
    }

    public function isZero(): bool
    {
        return $this->hi === 0 && $this->lo === 0;
    }

    public function compare(self $other): int
    {
        if ($this->hi !== $other->hi) {
            return $this->hi <=> $other->hi;
        }

        return $this->lo <=> $other->lo;
    }

    public function compareInt(int $value): int
    {
        if ($value < 0) {
            return 1;
        }

        return $this->compare(self::fromInt($value));
    }

    public function addSmall(int $value): self
    {
        if ($value < 0) {
            throw new ParseError('Cannot add a negative value to UInt64.');
        }

        $lo = $this->lo + $value;
        $hi = $this->hi;

        if ($lo > self::UINT32_MASK) {
            $lo -= self::UINT32_BASE;
            ++$hi;
        }

        return self::fromUInt32($hi, $lo);
    }

    public function fitsSignedInt(): bool
    {
        if (PHP_INT_SIZE >= 8) {
            return $this->hi <= 0x7FFFFFFF;
        }

        return $this->hi === 0 && $this->lo <= 0x7FFFFFFF;
    }

    public function toInt(string $context): int
    {
        if (!$this->fitsSignedInt()) {
            throw new ParseError(sprintf('%s exceeds supported integer range.', $context));
        }

        return (int) (($this->hi * self::UINT32_BASE) + $this->lo);
    }

    public function toHex(): string
    {
        return sprintf('%08X%08X', $this->hi, $this->lo);
    }

    private function assertUint32(int $value): void
    {
        if ($value < 0 || $value > self::UINT32_MASK) {
            throw new ParseError('UInt64 components must be unsigned 32-bit integers.');
        }
    }
}
