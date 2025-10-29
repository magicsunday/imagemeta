<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use LogicException;

/**
 * Represents scalar property list values.
 */
final readonly class ApplePlistScalar implements ApplePlistValue
{
    public function __construct(private bool|float|int|string|null $value)
    {
    }

    public function value(): bool|float|int|string|null
    {
        return $this->value;
    }

    public function isString(): bool
    {
        return is_string($this->value);
    }

    public function isInt(): bool
    {
        return is_int($this->value);
    }

    public function isFloat(): bool
    {
        return is_float($this->value);
    }

    public function isBool(): bool
    {
        return is_bool($this->value);
    }

    public function isNull(): bool
    {
        return $this->value === null;
    }

    public function asString(): string
    {
        if (!is_string($this->value)) {
            throw new LogicException('The property list value is not a string.');
        }

        return $this->value;
    }

    public function asInt(): int
    {
        if (!is_int($this->value)) {
            throw new LogicException('The property list value is not an integer.');
        }

        return $this->value;
    }

    public function asFloat(): float
    {
        if (is_float($this->value)) {
            return $this->value;
        }

        if (is_int($this->value)) {
            return (float) $this->value;
        }

        throw new LogicException('The property list value is not a floating point number.');
    }

    public function asBool(): bool
    {
        if (!is_bool($this->value)) {
            throw new LogicException('The property list value is not a boolean.');
        }

        return $this->value;
    }
}
