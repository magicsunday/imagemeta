<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

use MagicSunday\ImageMeta\Core\ParseError;

use function array_key_exists;
use function array_merge;
use function is_string;
use function sprintf;

/**
 * Represents an XMP LanguageAlternative container (rdf:Alt).
 *
 * Each entry carries an xml:lang qualifier paired with its text value.
 */
final readonly class XmpLanguageAlternative
{
    /**
     * @var array<int, array{lang: string, value: string}>
     */
    public array $entries;

    /**
     * @param array<int, array{lang: string, value: string}> $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $this->normalizeEntries($entries);
    }

    /**
     * Creates a language alternative from scalar or list values without qualifiers.
     *
     * @param array<int, string>|string $value
     */
    public static function fromValue(array|string $value): self
    {
        $entries = [];

        if (is_string($value)) {
            $entries[] = [
                'lang'  => '',
                'value' => $value,
            ];
        } else {
            foreach ($value as $item) {
                $entries[] = [
                    'lang'  => '',
                    'value' => $item,
                ];
            }
        }

        return new self($entries);
    }

    /**
     * Returns the default value (x-default if present, otherwise the first entry).
     */
    public function defaultValue(): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['lang'] === 'x-default') {
                return $entry['value'];
            }
        }

        $first = $this->entries[0] ?? null;

        return $first['value'] ?? null;
    }

    /**
     * Returns the first value for the requested language.
     */
    public function valueFor(string $language): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['lang'] === $language) {
                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * Returns the list of values in their stored order.
     *
     * @return list<string>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->entries as $entry) {
            $values[] = $entry['value'];
        }

        return $values;
    }

    /**
     * Returns the list of languages in their stored order.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $languages = [];

        foreach ($this->entries as $entry) {
            $languages[] = $entry['lang'];
        }

        return $languages;
    }

    /**
     * Returns a language-to-value map, using the first occurrence per language.
     *
     * @return array<string, string>
     */
    public function toMap(): array
    {
        $map = [];

        foreach ($this->entries as $entry) {
            $language = $entry['lang'];

            if (!array_key_exists($language, $map)) {
                $map[$language] = $entry['value'];
            }
        }

        return $map;
    }

    /**
     * Merges two language alternatives, preserving entry order and duplicates.
     */
    public static function merge(self $first, self $second): self
    {
        return new self(array_merge($first->entries, $second->entries));
    }

    /**
     * Normalizes entries by placing x-default first when present.
     *
     * @param array<int, array{lang: string, value: string}> $entries
     *
     * @return array<int, array{lang: string, value: string}>
     */
    private function normalizeEntries(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $seen         = [];
        $defaultIndex = null;

        foreach ($entries as $index => $entry) {
            $lang = $entry['lang'];

            if ($lang !== '' && array_key_exists($lang, $seen)) {
                throw new ParseError(sprintf(
                    'Duplicate xml:lang "%s" in rdf:Alt per XMP spec LanguageAlternative',
                    $lang,
                ));
            }

            if ($lang !== '') {
                $seen[$lang] = true;
            }

            if ($lang === 'x-default') {
                $defaultIndex = $index;
            }
        }

        if ($defaultIndex === null || $defaultIndex === 0) {
            return $entries;
        }

        $default = $entries[$defaultIndex];

        $normalized = [$default];

        foreach ($entries as $index => $entry) {
            if ($index === $defaultIndex) {
                continue;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }
}
