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
use function sprintf;
use function trim;

/**
 * Performs a lightweight XMP RDF/XML pass using \XMLReader to capture simple properties.
 */
final class XmpReader
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * Parses an XMP packet and returns a document containing discovered properties.
     *
     * The resulting document stores values keyed by Clark notation ("{namespace}local") and
     * flattens RDF containers (Bag/Seq/Alt) into PHP lists.
     *
     * @param string $xml The raw XML payload containing the XMP packet.
     *
     * @return XmpDocument Parsed XMP representation.
     */
    public function parse(string $xml): XmpDocument
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return new XmpDocument([]);
        }

        $data        = [];
        $elementPath = [];
        $textBuffers = [];
        $listBuffers = [];

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    $depth     = $reader->depth;
                    $namespace = $reader->namespaceURI ?? '';
                    $localName = $reader->localName;

                    $elementPath[$depth] = [$namespace, $localName];
                    $textBuffers[$depth] = '';

                    if ($namespace !== self::RDF_NAMESPACE) {
                        $listBuffers[$depth] = [];
                    }

                    if ($reader->isEmptyElement) {
                        if ($namespace !== self::RDF_NAMESPACE) {
                            $value = $this->finalizeValue($listBuffers[$depth] ?? [], $textBuffers[$depth] ?? '');
                            if ($value !== null) {
                                $data[$this->buildClarkName($namespace, $localName)] = $value;
                            }
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
                    } elseif ($namespace !== self::RDF_NAMESPACE) {
                        $value = $this->finalizeValue($listBuffers[$depth] ?? [], $textBuffers[$depth] ?? '');
                        if ($value !== null) {
                            $data[$this->buildClarkName($namespace, $localName)] = $value;
                        }
                    }

                    unset($elementPath[$depth], $textBuffers[$depth], $listBuffers[$depth]);
                    break;
            }
        }

        $reader->close();

        return new XmpDocument($data);
    }

    /**
     * Finalises the collected container/list data for the current element.
     *
     * @param array<int, string> $items
     *
     * @return array|string|null
     */
    private function finalizeValue(array $items, string $text): array|string|null
    {
        $items = array_values(array_filter($items, static fn (string $value): bool => $value !== ''));
        if ($items !== []) {
            return $items;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
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
