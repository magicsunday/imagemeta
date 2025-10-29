<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use function array_key_exists;

/**
 * Represents dictionary property list values.
 */
final class ApplePlistDictionary implements ApplePlistValue
{
    /**
     * @var array<string, ApplePlistValue>
     */
    private array $values;

    /**
     * @param array<string, ApplePlistValue> $values
     */
    public function __construct(array $values)
    {
        /** @var array<string, ApplePlistValue> $values */
        $this->values = $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): ?ApplePlistValue
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<string, ApplePlistValue>
     */
    public function entries(): array
    {
        return $this->values;
    }

    public function with(string $key, ApplePlistValue $value): self
    {
        $clone               = clone $this;
        $clone->values[$key] = $value;

        return $clone;
    }
}
