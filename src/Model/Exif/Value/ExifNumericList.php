<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif\Value;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Represents an ordered list of integer or floating point EXIF values.
 */
final readonly class ExifNumericList implements Countable, IteratorAggregate
{
    /**
     * @param list<int|float> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Returns the number of contained numeric values.
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Retrieves an iterator for the list contents.
     *
     * @return Traversable<int, int|float>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    /**
     * Returns the first numeric value from the list.
     */
    public function first(): int|float|null
    {
        return $this->values[0] ?? null;
    }

    /**
     * Exposes the raw numeric values as a sequential array.
     *
     * @return list<int|float>
     */
    public function values(): array
    {
        return $this->values;
    }
}
