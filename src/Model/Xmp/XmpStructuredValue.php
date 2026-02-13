<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

use function array_key_exists;
use function is_array;
use function sprintf;

/**
 * Represents an RDF structured property value keyed by Clark notation.
 */
final readonly class XmpStructuredValue
{
    /**
     * @param array<string, array<int, string>|string|XmpLanguageAlternative|self> $fields Structured child values keyed by Clark notation.
     */
    public function __construct(
        public array $fields,
    ) {
    }

    /**
     * Merges two structured values while preserving existing values and multiplicity where possible.
     */
    public static function merge(self $first, self $second): self
    {
        $merged = $first->fields;

        foreach ($second->fields as $key => $value) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;

                continue;
            }

            $merged[$key] = self::mergeFieldValue($merged[$key], $value);
        }

        return new self($merged);
    }

    /**
     * Returns a child value by namespace and local name.
     *
     * @return array<int, string>|string|XmpLanguageAlternative|self|null
     */
    public function get(string $namespaceUri, string $localName): array|string|XmpLanguageAlternative|self|null
    {
        $key = $this->buildClarkName($namespaceUri, $localName);

        return $this->fields[$key] ?? null;
    }

    /**
     * Returns the child value interpreted as string when possible.
     */
    public function string(string $namespaceUri, string $localName): ?string
    {
        return XmpDocument::stringFromValue($this->get($namespaceUri, $localName));
    }

    /**
     * @param array<int, string>|string|XmpLanguageAlternative|self $existing
     * @param array<int, string>|string|XmpLanguageAlternative|self $value
     *
     * @return array<int, string>|string|XmpLanguageAlternative|self
     */
    private static function mergeFieldValue(array|string|XmpLanguageAlternative|self $existing, array|string|XmpLanguageAlternative|self $value): array|string|XmpLanguageAlternative|self
    {
        if (($existing instanceof self) && ($value instanceof self)) {
            return self::merge($existing, $value);
        }

        if (($existing instanceof self) || ($value instanceof self)) {
            // Keep the first observed representation when value forms differ.
            return $existing;
        }

        if (($existing instanceof XmpLanguageAlternative) && ($value instanceof XmpLanguageAlternative)) {
            return XmpLanguageAlternative::merge($existing, $value);
        }

        if (($existing instanceof XmpLanguageAlternative) || ($value instanceof XmpLanguageAlternative)) {
            // Keep the first observed representation when value forms differ.
            return $existing;
        }

        if (is_array($existing)) {
            if (is_array($value)) {
                return [...$existing, ...$value];
            }

            return [...$existing, $value];
        }

        if (is_array($value)) {
            return [$existing, ...$value];
        }

        if ($existing === $value) {
            return $existing;
        }

        return [$existing, $value];
    }

    /**
     * Builds a Clark notation key for the given namespace/local name pair.
     */
    private function buildClarkName(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }
}
