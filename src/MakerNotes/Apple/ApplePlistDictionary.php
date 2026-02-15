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
final class ApplePlistDictionary implements ApplePlistValueInterface
{
    /**
     * @param array<string, ApplePlistValueInterface> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Indicates whether a key exists in the dictionary.
     *
     * @param string $key Dictionary key.
     *
     * @return bool True when the key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Returns a dictionary value for the given key.
     *
     * @param string $key Dictionary key.
     *
     * @return ApplePlistValueInterface|null Value at key or null when missing.
     */
    public function get(string $key): ?ApplePlistValueInterface
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Returns all key-value entries in this plist dictionary.
     *
     * @return array<string, ApplePlistValueInterface> Dictionary entries.
     */
    public function entries(): array
    {
        return $this->values;
    }

    /**
     * Returns a cloned dictionary with an additional entry.
     *
     * @param string                   $key   Dictionary key.
     * @param ApplePlistValueInterface $value Value to add.
     *
     * @return self Updated dictionary.
     */
    public function with(string $key, ApplePlistValueInterface $value): self
    {
        $clone               = clone $this;
        $clone->values[$key] = $value;

        return $clone;
    }

    /**
     * Resolves the dictionary using keyed-archive dispatch logic.
     *
     * @return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
     */
    public function resolveValue(KeyedArchiveUnarchiver $unarchiver): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        return $unarchiver->resolveDictionaryValue($this);
    }
}
