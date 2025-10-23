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

use function in_array;
use function sprintf;
use function trim;

/**
 * Lightweight streaming XMP (RDF/XML) parser backed by XMLReader.
 * The parser extracts a curated subset of common fields and stores them
 * using Clark notation while gracefully skipping everything else.
 */
final class XmpParser
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const string XMP_NAMESPACE = 'http://ns.adobe.com/xap/1.0/';

    private const string EXIF_NAMESPACE = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Parses the provided XMP payload into a document object.
     *
     * @param string $xmpXml The raw XMP XML fragment.
     *
     * @return XmpDocument Parsed values keyed by Clark notation.
     */
    public function parse(string $xmpXml): XmpDocument
    {
        $reader = XMLReader::XML($xmpXml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$reader instanceof XMLReader) {
            return new XmpDocument([]);
        }

        $properties = [];
        /** @var array{string, string}|null $pendingBag Tracks the surrounding element for dc:subject bags. */
        $pendingBag = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($pendingBag !== null && $this->matchesElement($reader, $pendingBag[0], $pendingBag[1])) {
                    $pendingBag = null;
                }

                continue;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $namespace = $reader->namespaceURI;
            $localName = $reader->localName;

            if ($namespace === self::RDF_NAMESPACE && $localName === 'Bag' && $pendingBag !== null) {
                $bag = $this->readBag($reader);
                if ($bag !== []) {
                    [$bagNs, $bagLocal]                          = $pendingBag;
                    $properties[$this->qname($bagNs, $bagLocal)] = $bag;
                }

                continue;
            }

            if ($namespace === self::DC_NAMESPACE && $localName === 'subject') {
                $pendingBag = $reader->isEmptyElement ? null : [$namespace, $localName];
                continue;
            }

            if ($this->isStringProperty($namespace, $localName)) {
                $text = $this->readElementText($reader);
                if ($text !== '') {
                    $properties[$this->qname($namespace, $localName)] = $text;
                }
            }
        }

        $reader->close();

        return new XmpDocument($properties);
    }

    /**
     * Determines whether the current element should be captured as a scalar string property.
     *
     * @param string $namespace Namespace URI associated with the element.
     * @param string $localName Local element name as read from the stream.
     *
     * @return bool True when the element maps to a scalar value in the resulting document.
     */
    private function isStringProperty(string $namespace, string $localName): bool
    {
        return ($namespace === self::XMP_NAMESPACE && in_array($localName, ['CreateDate', 'ModifyDate'], true))
            || ($namespace === self::EXIF_NAMESPACE && in_array($localName, ['DateTimeOriginal', 'OffsetTimeOriginal'], true));
    }

    /**
     * Checks whether the current reader position matches the provided qualified name.
     *
     * @param XMLReader $reader    XML reader currently positioned on an element.
     * @param string    $namespace Expected namespace URI.
     * @param string    $localName Expected local element name.
     *
     * @return bool True when the reader points at an element with the provided name.
     */
    private function matchesElement(XMLReader $reader, string $namespace, string $localName): bool
    {
        return $reader->namespaceURI === $namespace && $reader->localName === $localName;
    }

    /**
     * Builds a Clark notation qualified name for the provided pair.
     *
     * @param string $namespaceUri The element namespace URI.
     * @param string $localName    The element's local name.
     *
     * @return string
     */
    private function qname(string $namespaceUri, string $localName): string
    {
        return $namespaceUri !== ''
            ? sprintf('{%s}%s', $namespaceUri, $localName)
            : $localName;
    }

    /**
     * Reads the concatenated text content of the current element.
     *
     * @param XMLReader $reader The active XML reader.
     *
     * @return string
     */
    private function readElementText(XMLReader $reader): string
    {
        $text = '';
        if ($reader->isEmptyElement) {
            return $text;
        }

        $depth = $reader->depth;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                $text .= $reader->value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
        }

        return trim($text);
    }

    /**
     * Reads an rdf:Bag container into a list of items.
     *
     * @param XMLReader $reader The active XML reader.
     *
     * @return list<string>
     */
    private function readBag(XMLReader $reader): array
    {
        $items = [];
        if ($reader->isEmptyElement) {
            return $items;
        }

        $depth = $reader->depth;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT
                && $reader->namespaceURI === self::RDF_NAMESPACE
                && $reader->localName === 'li'
            ) {
                $value = $this->readElementText($reader);
                if ($value !== '') {
                    $items[] = $value;
                }
            }
        }

        return $items;
    }
}
