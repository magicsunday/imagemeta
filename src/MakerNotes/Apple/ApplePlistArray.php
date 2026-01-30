<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use function count;

/**
 * Represents array property list values.
 */
final class ApplePlistArray implements ApplePlistValue
{
    /**
     * @param list<ApplePlistValue> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Returns the array values stored in this plist array.
     *
     * @return list<ApplePlistValue> List of plist values.
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Indicates whether the array is empty.
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Returns the number of entries in the array.
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Returns the value at the specified index, if present.
     *
     * @param int $index Zero-based index.
     *
     * @return ApplePlistValue|null Value at index or null.
     */
    public function get(int $index): ?ApplePlistValue
    {
        return $this->values[$index] ?? null;
    }
}
