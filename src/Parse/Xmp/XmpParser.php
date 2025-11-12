<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use XMLReader;

use function array_filter;
use function array_key_exists;
use function array_values;
use function is_array;
use function sprintf;
use function trim;

/**
 * Performs a lightweight XMP RDF/XML pass using \XMLReader to capture simple properties.
 */
final class XmpParser
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * Parses an XMP packet and returns a document containing discovered properties.
     *
     * The resulting document stores values keyed by Clark notation ("{namespace}local") and
     * flattens RDF containers (Bag/Seq/Alt) into PHP lists. Namespace prefixes are extracted
     * from xmlns declarations for later display.
     *
     * @param string $xml The raw XML payload containing the XMP packet.
     *
     * @return XmpDocument Parsed XMP representation.
     */
    public function parse(string $xml): XmpDocument
    {
        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$reader instanceof XMLReader) {
            return new XmpDocument([], []);
        }

        /** @var array<string, array<int, string>|string> $data */
        $data = [];
        /** @var array<string, string> $namespacePrefixes Maps namespace URI to prefix */
        $namespacePrefixes = [];
        /** @var array<int, array{string, string}> $elementPath */
        $elementPath = [];
        /** @var array<int, string> $textBuffers */
        $textBuffers = [];
        /** @var array<int, list<string>> $listBuffers */
        $listBuffers = [];

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    $depth     = $reader->depth;
                    $namespace = $reader->namespaceURI;
                    $localName = $reader->localName;

                    $elementPath[$depth] = [$namespace, $localName];
                    $textBuffers[$depth] = '';

                    if ($namespace !== self::RDF_NAMESPACE) {
                        $listBuffers[$depth] = [];
                    }

                    // XMP Specification Part 1 §7.2: Extract namespace prefix declarations
                    $this->extractNamespacePrefixes($reader, $namespacePrefixes);

                    // XMP Specification Part 1 §7.9.2.2: Properties may be encoded as attributes
                    // on rdf:Description or other elements. Extract non-structural attributes.
                    $this->extractAttributes($reader, $data);

                    if ($reader->isEmptyElement) {
                        if ($namespace !== self::RDF_NAMESPACE) {
                            $this->storeFinalizedValue(
                                $data,
                                $listBuffers,
                                $textBuffers,
                                $depth,
                                $namespace,
                                $localName,
                            );
                        }

                        unset($elementPath[$depth], $textBuffers[$depth], $listBuffers[$depth]);
                    }

                    break;

                case XMLReader::TEXT:
                case XMLReader::WHITESPACE:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                case XMLReader::CDATA:
                    $depth = $reader->depth - 1;
                    if ($depth >= 0 && array_key_exists($depth, $textBuffers)) {
                        $textBuffers[$depth] .= $reader->value;
                    }

                    break;

                case XMLReader::END_ELEMENT:
                    $depth = $reader->depth;
                    $info  = $elementPath[$depth] ?? null;
                    if ($info === null) {
                        break;
                    }

                    [$namespace, $localName] = $info;
                    if ($namespace === self::RDF_NAMESPACE && $localName === 'li') {
                        $text = trim($textBuffers[$depth] ?? '');
                        if ($text !== '') {
                            for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
                                if (isset($listBuffers[$parentDepth])) {
                                    $listBuffers[$parentDepth][] = $text;
                                    break;
                                }
                            }
                        }
                    } elseif ($namespace === self::RDF_NAMESPACE && $localName === 'value') {
                        // XMP Specification Part 1 §7.9.3: Qualified properties encode their primary value via rdf:value.
                        $text = trim($textBuffers[$depth] ?? '');
                        if ($text !== '') {
                            for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
                                $parentInfo = $elementPath[$parentDepth] ?? null;
                                if ($parentInfo === null) {
                                    continue;
                                }

                                [$parentNamespace, $parentLocalName] = $parentInfo;

                                if ($parentNamespace === self::RDF_NAMESPACE && $parentLocalName === 'li') {
                                    $existing = $textBuffers[$parentDepth] ?? '';
                                    $textBuffers[$parentDepth] = $existing . $text;
                                    break;
                                }

                                if ($parentNamespace !== self::RDF_NAMESPACE) {
                                    $existing = $textBuffers[$parentDepth] ?? '';
                                    $textBuffers[$parentDepth] = $existing . $text;
                                    break;
                                }
                            }
                        }
                    } elseif ($namespace !== self::RDF_NAMESPACE) {
                        $this->storeFinalizedValue(
                            $data,
                            $listBuffers,
                            $textBuffers,
                            $depth,
                            $namespace,
                            $localName,
                        );
                    }

                    unset($elementPath[$depth], $textBuffers[$depth], $listBuffers[$depth]);
                    break;
            }
        }

        $reader->close();

        return new XmpDocument($data, $namespacePrefixes);
    }

    /**
     * Extracts namespace prefix declarations from element attributes.
     *
     * XMP Specification Part 1 §7.2 defines namespace declarations as xmlns:prefix attributes.
     * This method captures the mapping from namespace URI to prefix for display purposes.
     *
     * @param XMLReader                $reader             Active XMLReader positioned on an element.
     * @param array<string, string> &$namespacePrefixes Target map for namespace URI => prefix.
     */
    private function extractNamespacePrefixes(XMLReader $reader, array &$namespacePrefixes): void
    {
        if (!$reader->hasAttributes) {
            return;
        }

        $reader->moveToFirstAttribute();

        do {
            $attrNamespace = $reader->namespaceURI;
            $attrLocalName = $reader->localName;

            // XMP Specification Part 1 §7.2: Capture namespace declarations (xmlns:*)
            if ($attrNamespace === 'http://www.w3.org/2000/xmlns/') {
                $namespaceUri = $reader->value;
                $prefix       = $attrLocalName;

                // Store the mapping if not already present (first declaration wins)
                if ($namespaceUri !== '' && !isset($namespacePrefixes[$namespaceUri])) {
                    $namespacePrefixes[$namespaceUri] = $prefix;
                }
            }
        } while ($reader->moveToNextAttribute());

        // Return to the element node after attribute traversal
        $reader->moveToElement();
    }

    /**
     * Extracts attribute properties from the current element.
     *
     * XMP Specification Part 1 §7.9.2.2 allows simple properties to be encoded
     * as attributes on rdf:Description or other elements. This method filters
     * out namespace declarations (xmlns:*) and RDF structural attributes
     * (rdf:about, rdf:ID, rdf:nodeID, rdf:parseType) while capturing actual
     * property values.
     *
     * @param XMLReader                                    $reader Active XMLReader positioned on an element.
     * @param array<string, array<int, string>|string> &$data   Target map for discovered properties.
     */
    private function extractAttributes(XMLReader $reader, array &$data): void
    {
        if (!$reader->hasAttributes) {
            return;
        }

        $reader->moveToFirstAttribute();

        do {
            $attrNamespace = $reader->namespaceURI;
            $attrLocalName = $reader->localName;

            // XMP Specification Part 1 §7.2: Skip namespace declarations (xmlns:*)
            if ($attrNamespace === 'http://www.w3.org/2000/xmlns/') {
                continue;
            }

            // XMP Specification Part 1 §7.9.2.2: Skip RDF structural attributes
            if (
                $attrNamespace === self::RDF_NAMESPACE
                && (
                    $attrLocalName === 'about'
                    || $attrLocalName === 'ID'
                    || $attrLocalName === 'nodeID'
                    || $attrLocalName === 'parseType'
                )
            ) {
                continue;
            }

            // Capture the attribute value as a property
            $value = $reader->value;

            if ($value !== '') {
                $key = $this->buildClarkName($attrNamespace, $attrLocalName);
                $this->storeValue($data, $key, $value);
            }
        } while ($reader->moveToNextAttribute());

        // Return to the element node after attribute traversal
        $reader->moveToElement();
    }

    /**
     * Stores a value derived from buffered list/text information.
     *
     * @param array<string, array<int, string>|string> $data
     * @param array<int, list<string>>                 $listBuffers
     * @param array<int, string>                       $textBuffers
     */
    private function storeFinalizedValue(
        array &$data,
        array $listBuffers,
        array $textBuffers,
        int $depth,
        string $namespace,
        string $localName,
    ): void {
        /** @var list<string> $items */
        $items = $listBuffers[$depth] ?? [];
        $text  = $textBuffers[$depth] ?? '';

        $value = $this->finalizeValue($items, $text);

        if ($value === null) {
            return;
        }

        $this->storeValue(
            $data,
            $this->buildClarkName($namespace, $localName),
            $value,
        );
    }

    /**
     * Finalises the collected container/list data for the current element.
     *
     * @param list<string> $items
     * @param string       $text
     *
     * @return list<string>|string|null
     */
    private function finalizeValue(array $items, string $text): array|string|null
    {
        $filtered = array_values(array_filter(
            $items,
            static fn (string $value): bool => $value !== '',
        ));

        if ($filtered !== []) {
            return $filtered;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Stores a value in the result map while merging multiple occurrences.
     *
     * @param array<string, string|array<int, string>> $data
     * @param list<string>|string                      $value
     */
    private function storeValue(array &$data, string $key, array|string $value): void
    {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;

            return;
        }

        $existing = $data[$key];

        if (is_array($existing)) {
            if (is_array($value)) {
                $data[$key] = [...$existing, ...$value];
            } else {
                $existing[] = $value;
                $data[$key] = $existing;
            }

            return;
        }

        if (is_array($value)) {
            $data[$key] = [$existing, ...$value];

            return;
        }

        $data[$key] = [$existing, $value];
    }

    /**
     * Combines namespace URI and local name into a Clark notation key.
     *
     * @param string $namespaceUri Namespace URI assigned to the element.
     * @param string $localName    Element name without namespace prefix.
     *
     * @return string Clark notation string representing the element.
     */
    private function buildClarkName(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }
}
