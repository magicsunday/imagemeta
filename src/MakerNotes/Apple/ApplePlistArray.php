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
     * @return list<ApplePlistValue>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function get(int $index): ?ApplePlistValue
    {
        return $this->values[$index] ?? null;
    }
}
