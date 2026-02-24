<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Represents scalar property list values.
 */
final readonly class ApplePlistScalar implements ApplePlistValueInterface
{
    /**
     * @param bool|float|int|string|null $value Scalar value to wrap.
     */
    public function __construct(private bool|float|int|string|null $value)
    {
    }

    /**
     * Returns the raw scalar value.
     */
    public function value(): bool|float|int|string|null
    {
        return $this->value;
    }

    /**
     * Indicates whether the value is a string.
     */
    public function isString(): bool
    {
        return is_string($this->value);
    }

    /**
     * Indicates whether the value is an integer.
     */
    public function isInt(): bool
    {
        return is_int($this->value);
    }

    /**
     * Indicates whether the value is a float.
     */
    public function isFloat(): bool
    {
        return is_float($this->value);
    }

    /**
     * Indicates whether the value is a boolean.
     */
    public function isBool(): bool
    {
        return is_bool($this->value);
    }

    /**
     * Indicates whether the value is null.
     */
    public function isNull(): bool
    {
        return $this->value === null;
    }

    /**
     * Returns the value as a string.
     *
     * @throws ParseError When the value is not a string.
     */
    public function asString(): string
    {
        if (!is_string($this->value)) {
            throw new ParseError('The property list value is not a string.', 1861);
        }

        return $this->value;
    }

    /**
     * Returns the value as an integer.
     *
     * @throws ParseError When the value is not an integer.
     */
    public function asInt(): int
    {
        if (!is_int($this->value)) {
            throw new ParseError('The property list value is not an integer.', 1862);
        }

        return $this->value;
    }

    /**
     * Returns the value as a float, promoting integers when needed.
     *
     * @throws ParseError When the value is not numeric.
     */
    public function asFloat(): float
    {
        if (is_float($this->value)) {
            return $this->value;
        }

        if (is_int($this->value)) {
            return (float) $this->value;
        }

        throw new ParseError('The property list value is not a floating point number.', 1863);
    }

    /**
     * Returns the value as a boolean.
     *
     * @throws ParseError When the value is not a boolean.
     */
    public function asBool(): bool
    {
        if (!is_bool($this->value)) {
            throw new ParseError('The property list value is not a boolean.', 1864);
        }

        return $this->value;
    }

    /**
     * Resolves scalar values using keyed-archive dispatch logic.
     */
    public function resolveValue(KeyedArchiveUnarchiver $unarchiver): ApplePlistScalar
    {
        return $unarchiver->resolveScalarValue($this);
    }
}
