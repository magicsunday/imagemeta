<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use MagicSunday\ImageMeta\Model\Xmp\XmpValueAccumulator;
use XMLReader;

use function array_key_exists;
use function defined;
use function in_array;
use function sprintf;
use function trim;

/**
 * Performs a lightweight XMP RDF/XML pass using \XMLReader to capture simple properties.
 */
final class XmpParser implements XmpParserInterface
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * Parses an XMP packet and returns a document containing discovered properties.
     *
     * The resulting document stores scalar/container values keyed by Clark notation
     * ("{namespace}local"), extracts `rdf:parseType="Resource"` properties as structured
     * values, and records namespace prefixes from xmlns declarations.
     *
     * @param string $xml The raw XML payload containing the XMP packet.
     *
     * @return XmpDocument Parsed XMP representation.
     */
    public function parse(string $xml): XmpDocument
    {
        $xmlOptions = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_NO_XXE')) {
            $xmlOptions |= LIBXML_NO_XXE;
        }

        $reader = XMLReader::XML($xml, null, $xmlOptions);
        if (!$reader instanceof XMLReader) {
            return new XmpDocument([], [], []);
        }

        /** @var array<string, array<int, string>|string|XmpLanguageAlternative> $data */
        $data = [];
        /** @var array<string, XmpStructuredValue> $structuredData */
        $structuredData = [];
        /** @var array<string, XmpContainer> $containerKinds */
        $containerKinds = [];
        /** @var array<string, string> $namespacePrefixes Maps namespace URI to prefix */
        $namespacePrefixes = [];
        /** @var array<int, array{string, string}> $elementPath */
        $elementPath = [];
        /** @var array<int, string> $textBuffers */
        $textBuffers = [];
        /** @var array<int, list<string>> $listBuffers */
        $listBuffers = [];
        /** @var array<int, list<array{lang: string, value: string}>> $altBuffers */
        $altBuffers = [];
        /** @var array<int, string> $listKinds */
        $listKinds = [];
        /** @var array<int, string> $languageBuffers */
        $languageBuffers = [];
        /** @var array<int, array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue>> $structuredBuffers */
        $structuredBuffers = [];

        // ISO 16684-1: XMP properties are expressed within an rdf:RDF graph.
        // Elements outside the graph (x:xmpmeta wrapper, packet PI, etc.) are
        // transport wrappers and must not produce property extraction.
        $insideRdfGraph = false;

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    $depth     = $reader->depth;
                    $namespace = $reader->namespaceURI;
                    $localName = $reader->localName;

                    $elementPath[$depth] = [$namespace, $localName];
                    $textBuffers[$depth] = '';

                    if ($namespace === self::RDF_NAMESPACE && $localName === 'RDF') {
                        $insideRdfGraph = true;
                    }

                    if ($namespace !== self::RDF_NAMESPACE) {
                        $listBuffers[$depth] = [];
                        $altBuffers[$depth]  = [];
                        $listKinds[$depth]   = '';

                        // XMP Specification Part 1 (RDF property forms): parseType="Resource"
                        // represents a structured value whose child elements belong to the parent property.
                        if ($this->hasRdfParseTypeResource($reader)) {
                            $structuredBuffers[$depth] = [];
                        }
                    }

                    if ($namespace === self::RDF_NAMESPACE && in_array($localName, ['Alt', 'Bag', 'Seq'], true)) {
                        for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
                            if (isset($listBuffers[$parentDepth])) {
                                $listKinds[$parentDepth] = $localName;
                                break;
                            }
                        }
                    }

                    if ($namespace === self::RDF_NAMESPACE && $localName === 'li') {
                        $languageBuffers[$depth] = $this->readXmlLang($reader);
                    }

                    // XMP Specification Part 1 §7.2: Extract namespace prefix declarations
                    $this->extractNamespacePrefixes($reader, $namespacePrefixes);

                    // XMP Specification Part 1 §7.9.2.2: Properties may be encoded as attributes
                    // on rdf:Description or other elements. Extract non-structural attributes.
                    if ($insideRdfGraph) {
                        $this->extractAttributes($reader, $data);
                    }

                    if ($reader->isEmptyElement) {
                        if ($insideRdfGraph && $namespace !== self::RDF_NAMESPACE) {
                            $this->storeFinalizedElementValue(
                                $data,
                                $structuredData,
                                $containerKinds,
                                $listBuffers,
                                $altBuffers,
                                $listKinds,
                                $textBuffers,
                                $structuredBuffers,
                                $depth,
                                $namespace,
                                $localName,
                            );
                        }

                        unset(
                            $elementPath[$depth],
                            $textBuffers[$depth],
                            $listBuffers[$depth],
                            $altBuffers[$depth],
                            $listKinds[$depth],
                            $structuredBuffers[$depth],
                        );
                    }

                    break;

                case XMLReader::TEXT:
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

                    if ($namespace === self::RDF_NAMESPACE && $localName === 'RDF') {
                        $insideRdfGraph = false;
                    }

                    if ($namespace === self::RDF_NAMESPACE && $localName === 'li') {
                        $text             = trim($textBuffers[$depth] ?? '');
                        $lang             = $languageBuffers[$depth] ?? '';
                        $parentListBuffer = $this->findParentListBuffer($listBuffers, $listKinds, $depth, $lang);
                        if ($parentListBuffer !== null) {
                            $parentDepth = $parentListBuffer['depth'];
                            $kind        = $parentListBuffer['kind'];

                            if ($kind === 'Alt') {
                                $altBuffers[$parentDepth][] = [
                                    'lang'  => $lang,
                                    'value' => $text,
                                ];
                            } else {
                                $listBuffers[$parentDepth][] = $text;
                            }
                        }
                    } elseif ($namespace === self::RDF_NAMESPACE && $localName === 'value') {
                        // XMP Specification Part 1 §7.9.3: Qualified properties encode their primary value via rdf:value.
                        $text = trim($textBuffers[$depth] ?? '');
                        for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
                            $parentInfo = $elementPath[$parentDepth] ?? null;
                            if ($parentInfo === null) {
                                continue;
                            }

                            [$parentNamespace, $parentLocalName] = $parentInfo;

                            if ($parentNamespace === self::RDF_NAMESPACE && $parentLocalName === 'li') {
                                $textBuffers[$parentDepth] = $text;
                                break;
                            }

                            if ($parentNamespace !== self::RDF_NAMESPACE) {
                                $textBuffers[$parentDepth] = $text;
                                break;
                            }
                        }
                    } elseif ($insideRdfGraph && $namespace !== self::RDF_NAMESPACE) {
                        $this->storeFinalizedElementValue(
                            $data,
                            $structuredData,
                            $containerKinds,
                            $listBuffers,
                            $altBuffers,
                            $listKinds,
                            $textBuffers,
                            $structuredBuffers,
                            $depth,
                            $namespace,
                            $localName,
                        );
                    }

                    unset(
                        $elementPath[$depth],
                        $textBuffers[$depth],
                        $listBuffers[$depth],
                        $altBuffers[$depth],
                        $listKinds[$depth],
                        $languageBuffers[$depth],
                        $structuredBuffers[$depth],
                    );
                    break;
            }
        }

        $reader->close();

        return new XmpDocument($data, $namespacePrefixes, $structuredData, $containerKinds);
    }

    /**
     * Extracts namespace prefix declarations from element attributes.
     *
     * XMP Specification Part 1 §7.2 defines namespace declarations as xmlns:prefix attributes.
     * This method captures the mapping from namespace URI to prefix for display purposes.
     *
     * @param XMLReader             $reader             Active XMLReader positioned on an element.
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
                $prefix       = ($attrLocalName === 'xmlns') ? '' : $attrLocalName;

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
     * @param XMLReader                                                       $reader Active XMLReader positioned on an element.
     * @param array<string, array<int, string>|string|XmpLanguageAlternative> &$data  Target map for discovered properties.
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

            // XMP/RDF qualifiers (xml:*) describe node/value semantics and are not standalone properties.
            if ($attrNamespace === 'http://www.w3.org/XML/1998/namespace') {
                continue;
            }

            // XMP Specification Part 1 §7.9.2.2: Skip RDF structural attributes
            if (
                $attrNamespace === self::RDF_NAMESPACE
                    && in_array($attrLocalName, ['about', 'datatype', 'ID', 'nodeID', 'parseType', 'resource'], true)
            ) {
                continue;
            }

            // Capture the attribute value as a property
            $value = $reader->value;

            $key = $this->buildClarkName($attrNamespace, $attrLocalName);
            $this->storeValue($data, $key, $value);
        } while ($reader->moveToNextAttribute());

        // Return to the element node after attribute traversal
        $reader->moveToElement();
    }

    /**
     * Detects RDF structured-property form encoded as rdf:parseType="Resource".
     *
     * XMP Specification Part 1 (RDF property forms) allows this representation for
     * structured values whose child elements belong to the parent property.
     */
    private function hasRdfParseTypeResource(XMLReader $reader): bool
    {
        if (!$reader->hasAttributes) {
            return false;
        }

        $isResource = false;

        $reader->moveToFirstAttribute();

        do {
            if (
                ($reader->namespaceURI === self::RDF_NAMESPACE)
                && ($reader->localName === 'parseType')
                && (trim($reader->value) === 'Resource')
            ) {
                $isResource = true;
                break;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $isResource;
    }

    /**
     * Stores a value derived from buffered list/text information.
     *
     * @param array<string, array<int, string>|string|XmpLanguageAlternative>                                $data
     * @param array<string, XmpStructuredValue>                                                              $structuredData
     * @param array<string, XmpContainer>                                                                    $containerKinds
     * @param array<int, list<string>>                                                                       $listBuffers
     * @param array<int, list<array{lang: string, value: string}>>                                           $altBuffers
     * @param array<int, string>                                                                             $listKinds
     * @param array<int, string>                                                                             $textBuffers
     * @param array<int, array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue>> $structuredBuffers
     */
    private function storeFinalizedElementValue(
        array &$data,
        array &$structuredData,
        array &$containerKinds,
        array $listBuffers,
        array $altBuffers,
        array $listKinds,
        array $textBuffers,
        array &$structuredBuffers,
        int $depth,
        string $namespace,
        string $localName,
    ): void {
        $value         = $this->finalizeElementValue($listBuffers, $altBuffers, $listKinds, $textBuffers, $structuredBuffers, $depth);
        $key           = $this->buildClarkName($namespace, $localName);
        $containerKind = XmpContainer::fromRdfContainerName($listKinds[$depth] ?? '');

        $parentStructuredDepth = $this->findStructuredParentDepth($depth - 1, $structuredBuffers);
        if ($parentStructuredDepth !== null) {
            $this->appendStructuredFieldValue($structuredBuffers[$parentStructuredDepth], $key, $value);

            return;
        }

        if (($containerKind instanceof XmpContainer) && !isset($containerKinds[$key])) {
            $containerKinds[$key] = $containerKind;
        }

        if ($value instanceof XmpStructuredValue) {
            if (isset($structuredData[$key])) {
                $structuredData[$key] = XmpStructuredValue::merge($structuredData[$key], $value);
            } else {
                $structuredData[$key] = $value;
            }

            return;
        }

        $this->storeValue($data, $key, $value);
    }

    /**
     * @param array<int, list<string>>                                                                       $listBuffers
     * @param array<int, list<array{lang: string, value: string}>>                                           $altBuffers
     * @param array<int, string>                                                                             $listKinds
     * @param array<int, string>                                                                             $textBuffers
     * @param array<int, array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue>> $structuredBuffers
     *
     * @return array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue
     */
    private function finalizeElementValue(
        array $listBuffers,
        array $altBuffers,
        array $listKinds,
        array $textBuffers,
        array $structuredBuffers,
        int $depth,
    ): array|string|XmpLanguageAlternative|XmpStructuredValue {
        /** @var list<string> $items */
        $items = $listBuffers[$depth] ?? [];
        /** @var list<array{lang: string, value: string}> $altItems */
        $altItems = $altBuffers[$depth] ?? [];
        $text     = $textBuffers[$depth] ?? '';
        $fields   = $structuredBuffers[$depth] ?? [];

        if ($fields !== []) {
            // XMP §7.9.3: When rdf:value promoted text to the parent, include it
            // in the structured value so qualified-property semantics are preserved.
            $trimmedText = trim($text);
            if ($trimmedText !== '') {
                $rdfValueKey          = sprintf('{%s}value', self::RDF_NAMESPACE);
                $fields[$rdfValueKey] = $trimmedText;
            }

            return new XmpStructuredValue($fields);
        }

        return $this->finalizeValue($items, $altItems, $listKinds[$depth] ?? '', $text);
    }

    /**
     * @param array<int, array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue>> $structuredBuffers
     */
    private function findStructuredParentDepth(int $startDepth, array $structuredBuffers): ?int
    {
        for ($depth = $startDepth; $depth >= 0; --$depth) {
            if (isset($structuredBuffers[$depth])) {
                return $depth;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue> $fields
     * @param array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue                $value
     *
     * @param-out array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue> $fields
     */
    private function appendStructuredFieldValue(array &$fields, string $key, array|string|XmpLanguageAlternative|XmpStructuredValue $value): void
    {
        if (!array_key_exists($key, $fields)) {
            $fields[$key] = $value;

            return;
        }

        $existing = $fields[$key];

        if (($existing instanceof XmpStructuredValue) && ($value instanceof XmpStructuredValue)) {
            $fields[$key] = XmpStructuredValue::merge($existing, $value);

            return;
        }

        if (($existing instanceof XmpStructuredValue) || ($value instanceof XmpStructuredValue)) {
            // Keep the first observed representation when value forms differ.
            return;
        }

        $temporary = ['value' => $existing];

        XmpValueAccumulator::merge($temporary, 'value', $value);

        $fields[$key] = $temporary['value'];
    }

    /**
     * Finalises the collected container/list data for the current element.
     *
     * @param list<string>                             $items
     * @param list<array{lang: string, value: string}> $altItems
     * @param string                                   $listKind
     * @param string                                   $text
     *
     * @return list<string>|string|XmpLanguageAlternative
     */
    private function finalizeValue(array $items, array $altItems, string $listKind, string $text): array|string|XmpLanguageAlternative
    {
        if ($listKind === 'Alt') {
            return new XmpLanguageAlternative($altItems);
        }

        if ($items !== []) {
            return $items;
        }

        return trim($text);
    }

    /**
     * Reads the xml:lang attribute value from the current element.
     */
    private function readXmlLang(XMLReader $reader): string
    {
        if (!$reader->hasAttributes) {
            return '';
        }

        $language = '';

        $reader->moveToFirstAttribute();

        do {
            if ($reader->namespaceURI === 'http://www.w3.org/XML/1998/namespace' && $reader->localName === 'lang') {
                $language = $reader->value;
                break;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $language;
    }

    /**
     * Finds the nearest parent list buffer and associated RDF container kind.
     *
     * @param array<int, list<string>> $listBuffers
     * @param array<int, string>       $listKinds
     *
     * @return array{depth:int, kind:string}|null
     */
    private function findParentListBuffer(array $listBuffers, array $listKinds, int $depth, string $lang): ?array
    {
        for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
            if (!isset($listBuffers[$parentDepth])) {
                continue;
            }

            $kind = $listKinds[$parentDepth] ?? '';

            // rdf:li is only valid inside rdf:Bag, rdf:Seq or rdf:Alt
            if ($kind === '') {
                continue;
            }

            $this->validateAltContainerLang($kind, $lang);

            return [
                'depth' => $parentDepth,
                'kind'  => $kind,
            ];
        }

        return null;
    }

    /**
     * Validates xml:lang requirements for rdf:Alt container items.
     */
    private function validateAltContainerLang(string $kind, string $lang): void
    {
        if (($kind !== 'Alt') || ($lang !== '')) {
            return;
        }

        throw new ParseError(
            'rdf:li in rdf:Alt must have an xml:lang qualifier per XMP spec LanguageAlternative.',
            ParseError::XMP_ALT_MISSING_LANG,
        );
    }

    /**
     * Stores a value in the result map while merging multiple occurrences.
     *
     * @param array<string, string|array<int, string>|XmpLanguageAlternative> $data
     * @param array<int, string>|string|XmpLanguageAlternative                $value
     */
    private function storeValue(array &$data, string $key, array|string|XmpLanguageAlternative $value): void
    {
        XmpValueAccumulator::merge($data, $key, $value);
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
