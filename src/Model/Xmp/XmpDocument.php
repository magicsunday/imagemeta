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
use function array_find;
use function array_key_exists;
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
use function substr;
use function trim;

/**
 * Immutable representation of an extracted XMP document keyed by Clark notation.
 */
final readonly class XmpDocument
{
    /** @var array<string, string|array<int, string>|XmpLanguageAlternative> */
    public array $data;

    /** @var array<string, string> Map of namespace URI to prefix (e.g., "http://ns.adobe.com/xap/1.0/" => "xmp") */
    public array $namespacePrefixes;

    /** @var array<string, XmpStructuredValue> Map of Clark notation => structured value. */
    public array $structuredData;

    /** @var array<string, XmpContainer> Map of Clark notation => RDF container kind. */
    public array $containerKinds;

    /**
     * @param array<string, string|array<int, string>|XmpLanguageAlternative> $data              Map of Clark notation => scalar/container value.
     * @param array<string, string>                                           $namespacePrefixes Map of namespace URI => prefix.
     * @param array<string, XmpStructuredValue>                               $structuredData    Map of Clark notation => structured property value.
     * @param array<string, XmpContainer>                                     $containerKinds    Map of Clark notation => RDF container kind.
     */
    public function __construct(
        array $data,
        array $namespacePrefixes = [],
        array $structuredData = [],
        array $containerKinds = [],
    ) {
        $this->data              = [...$data];
        $this->namespacePrefixes = [...$namespacePrefixes];
        $this->structuredData    = [...$structuredData];
        $this->containerKinds    = [...$containerKinds];
    }

    /**
     * Merges multiple XMP documents into a single aggregate.
     *
     * @param XmpDocument ...$documents Source documents to merge.
     */
    public static function merge(self ...$documents): self
    {
        if ($documents === []) {
            return new self([], [], []);
        }

        /** @var array<string, string|array<int, string>|XmpLanguageAlternative> $data */
        $data = [];
        /** @var array<string, string> $namespacePrefixes */
        $namespacePrefixes = [];
        /** @var array<string, XmpStructuredValue> $structuredData */
        $structuredData = [];
        /** @var array<string, XmpContainer> $containerKinds */
        $containerKinds = [];

        foreach ($documents as $document) {
            foreach ($document->data as $key => $value) {
                $data = self::accumulateValue($data, $key, $value);
            }

            foreach ($document->namespacePrefixes as $uri => $prefix) {
                if (!array_key_exists($uri, $namespacePrefixes)) {
                    $namespacePrefixes[$uri] = $prefix;
                }
            }

            foreach ($document->structuredData as $key => $value) {
                if (isset($structuredData[$key])) {
                    $structuredData[$key] = XmpStructuredValue::merge($structuredData[$key], $value);
                } else {
                    $structuredData[$key] = $value;
                }
            }

            foreach ($document->containerKinds as $key => $containerKind) {
                if (!isset($containerKinds[$key])) {
                    $containerKinds[$key] = $containerKind;
                }
            }
        }

        return new self($data, $namespacePrefixes, $structuredData, $containerKinds);
    }

    /**
     * Accumulates a value into the aggregate data map using the same semantics as the parser.
     *
     * @param array<string, string|array<int, string>|XmpLanguageAlternative> $data
     * @param array<int, string>|string|XmpLanguageAlternative                $value
     *
     * @return array<string, string|array<int, string>|XmpLanguageAlternative>
     */
    private static function accumulateValue(array $data, string $key, array|string|XmpLanguageAlternative $value): array
    {
        return XmpValueAccumulator::merge($data, $key, $value);
    }

    /**
     * Returns a trimmed string value for the given namespace/local name pair.
     */
    public function string(string $namespaceUri, string $localName): ?string
    {
        return self::stringFromValue($this->get($namespaceUri, $localName));
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
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if ($value instanceof XmpLanguageAlternative) {
            $items = array_map(trim(...), $value->values());

            return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        }

        if ($value instanceof XmpStructuredValue) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map(trim(...), array_values($value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Returns the raw string value without trimming for the given namespace/local name pair.
     */
    public function rawString(string $namespaceUri, string $localName): ?string
    {
        $value = $this->get($namespaceUri, $localName);

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof XmpLanguageAlternative) {
            return $value->defaultValue();
        }

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return null;
    }

    /**
     * Returns all string values without trimming for the given namespace/local name pair.
     *
     * @return list<string>
     */
    public function rawStringList(string $namespaceUri, string $localName): array
    {
        $value = $this->get($namespaceUri, $localName);

        if (is_string($value)) {
            return [$value];
        }

        if ($value instanceof XmpLanguageAlternative) {
            return $value->values();
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    /**
     * Indicates whether the document contains the specified property.
     */
    public function has(string $namespaceUri, string $localName): bool
    {
        return $this->get($namespaceUri, $localName) !== null;
    }

    /**
     * Interprets the property as an XMP boolean literal when possible.
     * XMP defines canonical boolean strings as "True" and "False".
     */
    public function bool(string $namespaceUri, string $localName): ?bool
    {
        $value = $this->string($namespaceUri, $localName);

        if ($value === null) {
            return null;
        }

        return match ($value) {
            'True'  => true,
            'False' => false,
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

        // XMP Integer: strict decimal integer grammar (A.2.2.3)
        if (preg_match('/^[+-]?\d+$/', $string) !== 1) {
            return null;
        }

        return (int) $string;
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
     * @return array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue|null
     */
    public function get(string $namespaceUri, string $localName): array|string|XmpLanguageAlternative|XmpStructuredValue|null
    {
        $key   = $this->buildClarkName($namespaceUri, $localName);
        $value = $this->data[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof XmpLanguageAlternative) {
            return $value;
        }

        if (is_array($value)) {
            /** @var array<int, string> $value */
            return $value;
        }

        return $this->structuredData[$key] ?? null;
    }

    /**
     * Finds the first property with the given local name independent of the namespace.
     *
     * @param string $localName Local property name to search for.
     *
     * @return array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue|null
     */
    public function find(string $localName): array|string|XmpLanguageAlternative|XmpStructuredValue|null
    {
        $value = array_find(
            $this->data,
            fn (array|string|XmpLanguageAlternative $value, string $key): bool => $this->matchesLocalName($key, $localName)
        );

        if ($value !== null) {
            return $value;
        }

        return array_find(
            $this->structuredData,
            fn (XmpStructuredValue $entry, string $key): bool => $this->matchesLocalName($key, $localName)
        );
    }

    /**
     * Returns the language alternative container for the specified property.
     */
    public function languageAlternative(string $namespaceUri, string $localName): ?XmpLanguageAlternative
    {
        $value = $this->get($namespaceUri, $localName);

        return $value instanceof XmpLanguageAlternative ? $value : null;
    }

    /**
     * Returns the structured property value for the specified property.
     */
    public function structured(string $namespaceUri, string $localName): ?XmpStructuredValue
    {
        $value = $this->get($namespaceUri, $localName);

        return $value instanceof XmpStructuredValue ? $value : null;
    }

    /**
     * Returns the RDF container kind for the specified property when present.
     */
    public function containerType(string $namespaceUri, string $localName): ?XmpContainer
    {
        $key = $this->buildClarkName($namespaceUri, $localName);

        return $this->containerKinds[$key] ?? null;
    }

    /**
     * Resolves the first non-empty textual value from supported XMP value forms.
     *
     * @param array<int, string|XmpStructuredValue>|string|XmpLanguageAlternative|XmpStructuredValue|null $value
     *
     * @return string|null First non-empty textual value or null.
     */
    public static function stringFromValue(array|string|XmpLanguageAlternative|XmpStructuredValue|null $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if ($value instanceof XmpLanguageAlternative) {
            $text = $value->defaultValue();

            if ($text === null) {
                return null;
            }

            $trimmed = trim($text);

            return $trimmed === '' ? null : $trimmed;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $element) {
            if (!is_string($element)) {
                continue;
            }

            $trimmed = trim($element);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
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

            if (is_numeric($numerator) && is_numeric($denominator)) {
                $denominatorValue = (float) $denominator;
                if ($denominatorValue === 0.0) {
                    return null;
                }

                return (float) $numerator / $denominatorValue;
            }
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

        if (($clark !== '') && ($clark[0] === '{')) {
            $pos = strpos($clark, '}');
            if ($pos !== false) {
                return substr($clark, $pos + 1) === $localName;
            }
        }

        return false;
    }
}
