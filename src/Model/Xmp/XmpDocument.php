<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

use function array_filter;
use function array_key_exists;
use function array_find;
use function array_map;
use function array_values;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Immutable representation of an extracted XMP document keyed by Clark notation.
 */
final readonly class XmpDocument
{
    /**
     * @param array<string, string|array<int, string>> $data              Map of Clark notation => value
     * @param array<string, string>                    $namespacePrefixes Map of namespace URI => prefix
     */
    public function __construct(
        /**
         * @var array<string, string|array<int, string>>
         */
        public array $data,
        /**
         * @var array<string, string> Map of namespace URI to prefix (e.g., "http://ns.adobe.com/xap/1.0/" => "xmp")
         */
        public array $namespacePrefixes = [],
    ) {
    }

    /**
     * Merges multiple XMP documents into a single aggregate.
     *
     * @param XmpDocument ...$documents Source documents to merge.
     */
    public static function merge(self ...$documents): self
    {
        if ($documents === []) {
            return new self([], []);
        }

        /** @var array<string, string|array<int, string>> $data */
        $data = [];
        /** @var array<string, string> $namespacePrefixes */
        $namespacePrefixes = [];

        foreach ($documents as $document) {
            foreach ($document->data as $key => $value) {
                self::accumulateValue($data, $key, $value);
            }

            foreach ($document->namespacePrefixes as $uri => $prefix) {
                if (!array_key_exists($uri, $namespacePrefixes)) {
                    $namespacePrefixes[$uri] = $prefix;
                }
            }
        }

        return new self($data, $namespacePrefixes);
    }

    /**
     * Accumulates a value into the aggregate data map using the same semantics as the parser.
     *
     * @param array<string, string|array<int, string>> $data
     * @param list<string>|string                      $value
     */
    private static function accumulateValue(array &$data, string $key, array|string $value): void
    {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;

            return;
        }

        $existing = $data[$key];

        if (is_array($existing)) {
            if (is_array($value)) {
                $data[$key] = [...$existing, ...$value];

                return;
            }

            $existing[]    = $value;
            $data[$key] = $existing;

            return;
        }

        if (is_array($value)) {
            $data[$key] = [$existing, ...$value];

            return;
        }

        $data[$key] = [$existing, $value];
    }

    /**
     * Returns a trimmed string value for the given namespace/local name pair.
     */
    public function string(string $namespaceUri, string $localName): ?string
    {
        $value = $this->get($namespaceUri, $localName);

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $element) {
            $trimmed = trim($element);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Returns all string values while trimming whitespace and omitting empties.
     *
     * @return list<string>
     */
    public function stringList(string $namespaceUri, string $localName): array
    {
        $value = $this->get($namespaceUri, $localName);

        if (is_string($value)) {
            $parts = array_map(trim(...), explode(',', $value));

            return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map(trim(...), array_values($value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Indicates whether the document contains the specified property.
     */
    public function has(string $namespaceUri, string $localName): bool
    {
        return $this->get($namespaceUri, $localName) !== null;
    }

    /**
     * Interprets the property as boolean when possible.
     */
    public function bool(string $namespaceUri, string $localName): ?bool
    {
        $value = $this->string($namespaceUri, $localName);

        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);

        return match ($normalized) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * Returns the property value interpreted as integer when numeric.
     */
    public function int(string $namespaceUri, string $localName): ?int
    {
        $string = $this->string($namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        $numeric = self::parseNumericValue($string);

        return $numeric !== null ? (int) $numeric : null;
    }

    /**
     * Returns the property value interpreted as float when numeric.
     */
    public function float(string $namespaceUri, string $localName): ?float
    {
        $string = $this->string($namespaceUri, $localName);

        if ($string === null) {
            return null;
        }

        return self::parseNumericValue($string);
    }

    /**
     * Looks up a property by namespace URI and local name.
     *
     * @param string $namespaceUri Namespace URI that scopes the property.
     * @param string $localName    Local property name to retrieve.
     *
     * @return array<int, string>|string|null
     */
    public function get(string $namespaceUri, string $localName): array|string|null
    {
        $key   = $this->buildClarkName($namespaceUri, $localName);
        $value = $this->data[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            /** @var array<int, string> $value */
            return $value;
        }

        return null;
    }

    /**
     * Finds the first property with the given local name independent of the namespace.
     *
     * @param string $localName Local property name to search for.
     *
     * @return array<int, string>|string|null
     */
    public function find(string $localName): array|string|null
    {
        return array_find(
            $this->data,
            fn (array|string $value, string $key): bool => $this->matchesLocalName($key, $localName)
        );
    }

    /**
     * Parses textual numeric representations used by XMP properties.
     */
    public static function parseNumericValue(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        if (str_contains($trimmed, '/')) {
            [$numerator, $denominator] = explode('/', $trimmed, 2);
            $numerator                 = trim($numerator);
            $denominator               = trim($denominator);

            if ($denominator === '0' || $denominator === '-0') {
                return null;
            }

            if (is_numeric($numerator) && is_numeric($denominator)) {
                return (float) $numerator / (float) $denominator;
            }
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)/', $trimmed, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * Builds a Clark notation key for the given namespace/local name pair.
     *
     * @param string $namespaceUri Namespace URI that qualifies the property.
     * @param string $localName    Local property name as declared in the document.
     *
     * @return string Fully-qualified Clark notation value.
     */
    private function buildClarkName(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }

    /**
     * Checks whether the provided Clark notation belongs to the given local name.
     *
     * @param string $clark     Property key expressed in Clark notation.
     * @param string $localName Local property name to compare against.
     *
     * @return bool True when the local name part of the key matches the provided name.
     */
    private function matchesLocalName(string $clark, string $localName): bool
    {
        if ($clark === $localName) {
            return true;
        }

        if ($clark !== '' && $clark[0] === '{') {
            $pos = strpos($clark, '}');
            if ($pos !== false) {
                return substr($clark, $pos + 1) === $localName;
            }
        }

        return false;
    }
}
