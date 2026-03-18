<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Contract\XmpParserInterface;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use MagicSunday\ImageMeta\Model\Xmp\XmpValueAccumulator;
use XMLReader;

use function array_filter;
use function array_key_exists;
use function array_values;
use function defined;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function trim;

/**
 * Performs a lightweight XMP RDF/XML pass using \XMLReader to capture simple properties.
 */
final class XmpParser implements XmpParserInterface
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    private const int MAX_XML_DEPTH    = 240;

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

        $reader     = XMLReader::XML($xml, null, $xmlOptions);

        if (!$reader instanceof XMLReader) {
            return new XmpDocument([], [], []);
        }

        $state      = new XmpParseState();

        // ISO 16684-1: XMP properties are expressed within an rdf:RDF graph.
        // Elements outside the graph (x:xmpmeta wrapper, packet PI, etc.) are
        // transport wrappers and must not produce property extraction.

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    $this->handleElementNode($reader, $state);

                    break;

                case XMLReader::TEXT:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                case XMLReader::CDATA:
                    $this->handleTextLikeNode($reader, $state);

                    break;

                case XMLReader::END_ELEMENT:
                    $this->handleEndElementNode($state, $reader->depth);

                    break;
            }
        }

        $reader->close();

        return new XmpDocument($state->data, $state->namespacePrefixes, $state->structuredData, $state->containerKinds);
    }

    /**
     * Handles XMLReader element nodes and updates parse-state buffers.
     */
    private function handleElementNode(XMLReader $reader, XmpParseState $state): void
    {
        $depth                      = $reader->depth;

        if ($depth > self::MAX_XML_DEPTH) {
            throw new ParseError(
                sprintf('XMP XML nesting depth %d exceeds maximum allowed %d', $depth, self::MAX_XML_DEPTH),
                ParseError::XMP_XML_DEPTH_LIMIT_EXCEEDED,
            );
        }

        $namespace                  = $reader->namespaceURI;
        $localName                  = $reader->localName;

        $state->elementPath[$depth] = [$namespace, $localName];
        $state->textBuffers[$depth] = '';

        if (($namespace === self::RDF_NAMESPACE) && ($localName === 'RDF')) {
            $state->insideRdfGraph = true;
        }

        if ($namespace !== self::RDF_NAMESPACE) {
            $state->listBuffers[$depth] = [];
            $state->altBuffers[$depth]  = [];
            $state->listKinds[$depth]   = '';

            // XMP Specification Part 1 (RDF property forms): parseType="Resource"
            // represents a structured value whose child elements belong to the parent property.
            if ($this->hasRdfParseTypeResource($reader)) {
                $state->structuredBuffers[$depth] = [];
            }
        }

        if (($namespace === self::RDF_NAMESPACE) && in_array($localName, ['Alt', 'Bag', 'Seq'], true)) {
            for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
                if (isset($state->listBuffers[$parentDepth])) {
                    $state->listKinds[$parentDepth] = $localName;

                    break;
                }
            }
        }

        if (($namespace === self::RDF_NAMESPACE) && ($localName === 'li')) {
            $state->languageBuffers[$depth] = $this->readXmlLang($reader);

            // MWG Regions Specification and XMP §7.9.2.5: rdf:li items may use
            // parseType="Resource" to represent structured entries inside Bag/Seq containers.
            if ($this->hasRdfParseTypeResource($reader)) {
                $state->structuredBuffers[$depth] = [];
            }
        }

        // XMP Specification Part 1 §7.2: Extract namespace prefix declarations.
        $this->extractNamespacePrefixes($reader, $state);

        // XMP Specification Part 1 §7.9.2.2: Properties may be encoded as attributes
        // on rdf:Description or other elements. Extract non-structural attributes.
        if ($state->insideRdfGraph) {
            $this->extractAttributes($reader, $state);
        }

        if (!$reader->isEmptyElement) {
            return;
        }

        if ($state->insideRdfGraph && ($namespace !== self::RDF_NAMESPACE)) {
            $this->storeFinalizedElementValue($state, $depth, $namespace, $localName);
        }

        $state->clearDepthBuffers($depth);
    }

    /**
     * Handles XMLReader text-like nodes (TEXT/CDATA/SIGNIFICANT_WHITESPACE).
     */
    private function handleTextLikeNode(XMLReader $reader, XmpParseState $state): void
    {
        $depth = $reader->depth - 1;

        if (($depth >= 0) && array_key_exists($depth, $state->textBuffers)) {
            $state->textBuffers[$depth] .= $reader->value;
        }
    }

    /**
     * Handles XMLReader end-element nodes and finalizes buffered values.
     */
    private function handleEndElementNode(XmpParseState $state, int $depth): void
    {
        $info                    = $state->elementPath[$depth] ?? null;

        if ($info === null) {
            return;
        }

        [$namespace, $localName] = $info;

        if (($namespace === self::RDF_NAMESPACE) && ($localName === 'RDF')) {
            $state->insideRdfGraph = false;
        }

        if (($namespace === self::RDF_NAMESPACE) && ($localName === 'li')) {
            $this->finalizeRdfListItem($state, $depth);
        } elseif (($namespace === self::RDF_NAMESPACE) && ($localName === 'value')) {
            $this->propagateRdfValueToParent($state, $depth);
        } elseif ($state->insideRdfGraph && ($namespace !== self::RDF_NAMESPACE)) {
            $this->storeFinalizedElementValue($state, $depth, $namespace, $localName);
        }

        $state->clearDepthBuffers($depth);
    }

    /**
     * Finalizes a buffered rdf:li item into the nearest RDF container buffer.
     */
    private function finalizeRdfListItem(XmpParseState $state, int $depth): void
    {
        $text                               = trim($state->textBuffers[$depth] ?? '');
        $lang                               = $state->languageBuffers[$depth] ?? '';
        $fields                             = $state->structuredBuffers[$depth] ?? [];
        $parentListBuffer                   = $this->findParentListBuffer($state, $depth, $lang);

        if ($parentListBuffer === null) {
            return;
        }

        $parentDepth                        = $parentListBuffer['depth'];
        $kind                               = $parentListBuffer['kind'];

        if ($kind === 'Alt') {
            $state->altBuffers[$parentDepth][] = [
                'lang'  => $lang,
                'value' => $text,
            ];

            return;
        }

        if ($fields !== []) {
            $state->listBuffers[$parentDepth][] = new XmpStructuredValue($fields);

            return;
        }

        $state->listBuffers[$parentDepth][] = $text;
    }

    /**
     * Propagates rdf:value text to the nearest non-RDF parent value buffer.
     */
    private function propagateRdfValueToParent(XmpParseState $state, int $depth): void
    {
        // XMP Specification Part 1 §7.9.3: Qualified properties encode their primary value via rdf:value.
        $text = trim($state->textBuffers[$depth] ?? '');

        for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
            $parentInfo                          = $state->elementPath[$parentDepth] ?? null;

            if ($parentInfo === null) {
                continue;
            }

            [$parentNamespace, $parentLocalName] = $parentInfo;

            if (($parentNamespace === self::RDF_NAMESPACE) && ($parentLocalName === 'li')) {
                $state->textBuffers[$parentDepth] = $text;

                break;
            }

            if ($parentNamespace !== self::RDF_NAMESPACE) {
                $state->textBuffers[$parentDepth] = $text;

                break;
            }
        }
    }

    /**
     * Extracts namespace prefix declarations from element attributes.
     *
     * XMP Specification Part 1 §7.2 defines namespace declarations as xmlns:prefix attributes.
     * This method captures the mapping from namespace URI to prefix for display purposes.
     */
    private function extractNamespacePrefixes(XMLReader $reader, XmpParseState $state): void
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
                if (($namespaceUri !== '') && !isset($state->namespacePrefixes[$namespaceUri])) {
                    $state->namespacePrefixes[$namespaceUri] = $prefix;
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
     */
    private function extractAttributes(XMLReader $reader, XmpParseState $state): void
    {
        if (!$reader->hasAttributes) {
            return;
        }

        $reader->moveToFirstAttribute();

        do {
            $attrNamespace = $reader->namespaceURI;
            $attrLocalName = $reader->localName;

            if ($this->shouldSkipAttribute($attrNamespace, $attrLocalName)) {
                continue;
            }

            // Capture the attribute value as a property
            $value         = $reader->value;

            $key           = $this->buildClarkName($attrNamespace, $attrLocalName);
            $this->storeValue($state, $key, $value);
        } while ($reader->moveToNextAttribute());

        // Return to the element node after attribute traversal
        $reader->moveToElement();
    }

    /**
     * Determines whether an attribute is structural metadata and must not be extracted as a property.
     */
    private function shouldSkipAttribute(string $attrNamespace, string $attrLocalName): bool
    {
        // XMP Specification Part 1 §7.2: Skip namespace declarations (xmlns:*).
        if ($attrNamespace === 'http://www.w3.org/2000/xmlns/') {
            return true;
        }

        // XMP/RDF qualifiers (xml:*) describe node/value semantics and are not standalone properties.
        if ($attrNamespace === 'http://www.w3.org/XML/1998/namespace') {
            return true;
        }

        // XMP Specification Part 1 §7.9.2.2: Skip RDF structural attributes.
        return $attrNamespace === self::RDF_NAMESPACE
            && in_array($attrLocalName, ['about', 'datatype', 'ID', 'nodeID', 'parseType', 'resource', 'type'], true);
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
            if (($reader->namespaceURI === self::RDF_NAMESPACE) && ($reader->localName === 'parseType') && (trim($reader->value) === 'Resource')) {
                $isResource = true;

                break;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $isResource;
    }

    /**
     * Stores a value derived from buffered list/text information.
     */
    private function storeFinalizedElementValue(
        XmpParseState $state,
        int $depth,
        string $namespace,
        string $localName,
    ): void {
        $value                 = $this->finalizeElementValue($state, $depth);
        $key                   = $this->buildClarkName($namespace, $localName);
        $containerKind         = XmpContainer::fromRdfContainerName($state->listKinds[$depth] ?? '');

        $parentStructuredDepth = $this->findStructuredParentDepth($depth - 1, $state);

        if ($parentStructuredDepth !== null) {
            $this->appendStructuredFieldValue($state, $parentStructuredDepth, $key, $value);

            return;
        }

        if (($containerKind instanceof XmpContainer) && !isset($state->containerKinds[$key])) {
            $state->containerKinds[$key] = $containerKind;
        }

        if ($value instanceof XmpStructuredValue) {
            if (isset($state->structuredData[$key])) {
                $state->structuredData[$key] = XmpStructuredValue::merge($state->structuredData[$key], $value);
            } else {
                $state->structuredData[$key] = $value;
            }

            return;
        }

        // Narrow list<string|XmpStructuredValue> to list<string> for top-level storage.
        // Structured list items only occur in Bag/Seq containers with parseType="Resource"
        // entries and are fully handled via the structured parent path above.
        if (is_array($value)) {
            /** @var list<string> $stringItems */
            $stringItems = array_values(array_filter($value, is_string(...)));

            $this->storeValue($state, $key, $stringItems);

            return;
        }

        $this->storeValue($state, $key, $value);
    }

    /**
     * @return array<int, string|XmpStructuredValue>|string|XmpLanguageAlternative|XmpStructuredValue
     */
    private function finalizeElementValue(
        XmpParseState $state,
        int $depth,
    ): array|string|XmpLanguageAlternative|XmpStructuredValue {
        /** @var list<string|XmpStructuredValue> $items */
        $items    = $state->listBuffers[$depth] ?? [];
        /** @var list<array{lang: string, value: string}> $altItems */
        $altItems = $state->altBuffers[$depth] ?? [];
        $text     = $state->textBuffers[$depth] ?? '';
        $fields   = $state->structuredBuffers[$depth] ?? [];

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

        return $this->finalizeValue($items, $altItems, $state->listKinds[$depth] ?? '', $text);
    }

    private function findStructuredParentDepth(int $startDepth, XmpParseState $state): ?int
    {
        for ($depth = $startDepth; $depth >= 0; --$depth) {
            if (isset($state->structuredBuffers[$depth])) {
                return $depth;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|XmpStructuredValue>|string|XmpLanguageAlternative|XmpStructuredValue $value
     */
    private function appendStructuredFieldValue(
        XmpParseState $state,
        int $parentDepth,
        string $key,
        array|string|XmpLanguageAlternative|XmpStructuredValue $value,
    ): void {
        if (!array_key_exists($key, $state->structuredBuffers[$parentDepth])) {
            $state->structuredBuffers[$parentDepth][$key] = $value;

            return;
        }

        $existing                                     = $state->structuredBuffers[$parentDepth][$key];

        if (($existing instanceof XmpStructuredValue) && ($value instanceof XmpStructuredValue)) {
            $state->structuredBuffers[$parentDepth][$key] = XmpStructuredValue::merge($existing, $value);

            return;
        }

        if (($existing instanceof XmpStructuredValue) || ($value instanceof XmpStructuredValue)) {
            // Keep the first observed representation when value forms differ.
            return;
        }

        // Arrays containing XmpStructuredValue items (from structured rdf:li in Bag/Seq)
        // are stored directly without accumulator merging.
        if (is_array($value) || is_array($existing)) {
            $state->structuredBuffers[$parentDepth][$key] = $value;

            return;
        }

        /** @var array<string, string|XmpLanguageAlternative> $temporary */
        $temporary                                    = ['value' => $existing];

        $temporary                                    = XmpValueAccumulator::merge($temporary, 'value', $value);

        $state->structuredBuffers[$parentDepth][$key] = $temporary['value'];
    }

    /**
     * Finalises the collected container/list data for the current element.
     *
     * @param list<string|XmpStructuredValue>          $items
     * @param list<array{lang: string, value: string}> $altItems
     *
     * @return list<string|XmpStructuredValue>|string|XmpLanguageAlternative
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
            if (($reader->namespaceURI === 'http://www.w3.org/XML/1998/namespace') && ($reader->localName === 'lang')) {
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
     * @return array{depth: int, kind: string}|null
     */
    private function findParentListBuffer(XmpParseState $state, int $depth, string $lang): ?array
    {
        for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {
            if (!isset($state->listBuffers[$parentDepth])) {
                continue;
            }

            $kind = $state->listKinds[$parentDepth] ?? '';

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
     * @param array<int, string>|string|XmpLanguageAlternative $value
     */
    private function storeValue(XmpParseState $state, string $key, array|string|XmpLanguageAlternative $value): void
    {
        $state->data = XmpValueAccumulator::merge($state->data, $key, $value);
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
