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
 * Represents an ordered list of TIFF rational values.
 */
final readonly class ExifRationalList implements Countable, IteratorAggregate
{
    /**
     * @param list<ExifRational> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Creates a list from raw rational pairs.
     *
     * @param list<array{0:int,1:int}> $pairs
     */
    public static function fromPairs(array $pairs): self
    {
        $values = [];
        foreach ($pairs as $pair) {
            $values[] = new ExifRational($pair[0], $pair[1]);
        }

        return new self($values);
    }

    /**
     * Returns the number of contained rationals.
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Retrieves an iterator for the list contents.
     *
     * @return Traversable<int, ExifRational>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    /**
     * Returns the first rational from the list.
     */
    public function first(): ?ExifRational
    {
        return $this->values[0] ?? null;
    }

    /**
     * Retrieves the rational at the given index.
     */
    public function get(int $index): ?ExifRational
    {
        return $this->values[$index] ?? null;
    }

    /**
     * Returns the contained rationals as raw numerator/denominator tuples.
     *
     * @return list<array{0:int,1:int}>
     */
    public function toArray(): array
    {
        $pairs = [];
        foreach ($this->values as $value) {
            $pairs[] = $value->toArray();
        }

        return $pairs;
    }
}
