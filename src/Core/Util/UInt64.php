<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;

use function intdiv;
use function sprintf;

/**
 * Represents an unsigned 64-bit integer composed of two 32-bit halves.
 */
final readonly class UInt64
{
    private const int UINT32_MASK = 0xFFFFFFFF;

    private const int UINT32_BASE = 4_294_967_296;

    /**
     * Builds an unsigned 64-bit value from two unsigned 32-bit halves.
     *
     * @param int $hi Most significant 32-bit component.
     * @param int $lo Least significant 32-bit component.
     *
     * @throws ParseError When either component falls outside the unsigned 32-bit range.
     */
    public function __construct(
        private int $hi,
        private int $lo,
    ) {
        $this->assertUint32($this->hi);
        $this->assertUint32($this->lo);
    }

    /**
     * Creates an instance from two unsigned 32-bit parts.
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

        $hi = intdiv($value, self::UINT32_BASE);
        $lo = $value & self::UINT32_MASK;

        return new self($hi, $lo);
    }

    /**
     * Returns the most significant 32-bit component.
     *
     * @return int Unsigned 32-bit value representing the high part.
     */
    public function high(): int
    {
        return $this->hi;
    }

    /**
     * Returns the least significant 32-bit component.
     *
     * @return int Unsigned 32-bit value representing the low part.
     */
    public function low(): int
    {
        return $this->lo;
    }

    /**
     * Indicates whether both 32-bit components are zero.
     *
     * @return bool True when the value equals zero.
     */
    public function isZero(): bool
    {
        return $this->hi === 0 && $this->lo === 0;
    }

    /**
     * Compares this value with another 64-bit unsigned integer.
     *
     * @param self $other The value to compare against.
     *
     * @return int Negative, zero, or positive when this value is less than, equal to, or greater than $other.
     */
    public function compare(self $other): int
    {
        if ($this->hi !== $other->hi) {
            return $this->hi <=> $other->hi;
        }

        return $this->lo <=> $other->lo;
    }

    /**
     * Compares this value with a non-negative integer.
     *
     * @param int $value The integer to compare against.
     *
     * @return int Negative, zero, or positive when this value is less than, equal to, or greater than $value.
     */
    public function compareInt(int $value): int
    {
        if ($value < 0) {
            return 1;
        }

        return $this->compare(self::fromInt($value));
    }

    /**
     * Adds a non-negative integer that fits into 32 bits and returns the result.
     *
     * @param int $value The value to add; must be zero or positive.
     *
     * @return self New instance representing the sum.
     *
     * @throws ParseError When attempting to add a negative integer.
     */
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

    /**
     * Checks if the value fits within the current range of signed integers on the platform.
     *
     * @return bool True when the value can be represented as a signed int.
     */
    public function fitsSignedInt(): bool
    {
        if (PHP_INT_SIZE >= 8) {
            return $this->hi <= BitMask::INT31_MAX;
        }

        return $this->hi === 0 && $this->lo <= BitMask::INT31_MAX;
    }

    /**
     * Converts the value to a signed integer after checking the platform range.
     *
     * @param string $context Description used for the exception message when out of range.
     *
     * @return int Signed integer representation of the value.
     *
     * @throws ParseError When the value cannot be represented as a signed integer on this platform.
     */
    public function toInt(string $context): int
    {
        if (!$this->fitsSignedInt()) {
            throw new ParseError(sprintf('%s exceeds supported integer range.', $context));
        }

        return ($this->hi * self::UINT32_BASE) + $this->lo;
    }

    /**
     * Converts the value to an uppercase hexadecimal string without separators.
     *
     * @return string 16-character hexadecimal representation.
     */
    public function toHex(): string
    {
        return sprintf('%08X%08X', $this->hi, $this->lo);
    }

    /**
     * Converts the value to a decimal string representation.
     */
    public function toString(): string
    {
        if ($this->hi === 0) {
            return (string) $this->lo;
        }

        // For values that fit in signed int, use native conversion
        if ($this->fitsSignedInt()) {
            return (string) (($this->hi * self::UINT32_BASE) + $this->lo);
        }

        // For large values, use string arithmetic
        $result = (string) $this->hi;
        for ($i = 0; $i < 32; ++$i) {
            $result = bcmul($result, '2', 0);
        }

        return bcadd($result, (string) $this->lo, 0);
    }

    /**
     * Alias for addSmall(). Adds an unsigned integer value.
     */
    public function addUnsigned(int $value): self
    {
        return $this->addSmall($value);
    }

    /**
     * Checks if this value is less than another UInt64.
     */
    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    /**
     * Checks if this value is greater than another UInt64.
     */
    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /**
     * Ensures that the given value is within the unsigned 32-bit range.
     *
     * @param int $value Value to validate.
     *
     * @return void
     */
    private function assertUint32(int $value): void
    {
        if ($value < 0 || $value > self::UINT32_MASK) {
            throw new ParseError('UInt64 components must be unsigned 32-bit integers.');
        }
    }
}
