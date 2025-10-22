<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Performs a lightweight XMP RDF/XML pass using \XMLReader to capture simple properties.
 */
final class XmpReader
{
    /**
     * Parses an XMP packet and returns a document containing discovered properties.
     *
     * @param string $xml Raw XMP packet contents.
     *
     * @return XmpDocument
     */
    public function parse(string $xml): XmpDocument
    {
        $xr = new \XMLReader();
        if (!$xr->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return new XmpDocument([]);
        }
        $props = [];
        $stack = [];
        while ($xr->read()) {
            if ($xr->nodeType === \XMLReader::ELEMENT) {
                $stack[] = $this->qname($xr);
                // Simple property with text content
                if (!$xr->isEmptyElement) {
                    $text = $this->readText($xr);
                    if ($text !== null) {
                        $props[end($stack)] = $text;
                    }
                } else {
                    // Empty element: could be a bag/seq container; keep marker if needed
                    $props[end($stack)] ??= null;
                }
            } elseif ($xr->nodeType === \XMLReader::END_ELEMENT) {
                array_pop($stack);
            }
        }
        $xr->close();
        return new XmpDocument($props);
    }

    /**
     * Builds the fully-qualified name for the current XML element.
     *
     * @param \XMLReader $xr Active XML reader instance.
     *
     * @return string
     */
    private function qname(\XMLReader $xr): string
    {
        $prefix = $xr->prefix !== '' ? $xr->prefix . ':' : '';
        return $prefix . $xr->localName;
    }

    /**
     * Reads text content of the current element, flattening simple RDF containers.
     *
     * @param \XMLReader $xr Active XML reader instance positioned on an element.
     *
     * @return string|null
     */
    private function readText(\XMLReader $xr): ?string
    {
        $depth = $xr->depth;
        $txt = '';
        while ($xr->read()) {
            if ($xr->nodeType === \XMLReader::TEXT || $xr->nodeType === \XMLReader::CDATA) {
                $txt += $xr->value;
            } elseif ($xr->nodeType === \XMLReader::END_ELEMENT && $xr->depth === $depth) {
                break;
            } elseif ($xr->nodeType === \XMLReader::ELEMENT) {
                // For simple containers (rdf:Alt/Bag/Seq with rdf:li children)
                $name = $this->qname($xr);
                if (str_ends_with($name, 'li') && !$xr->isEmptyElement) {
                    $li = $this->readText($xr);
                    if ($li !== null) {
                        $txt .= ($txt !== '' ? ';' : '') . $li; // naive concat
                    }
                }
            }
        }
        $txt = trim($txt);
        return $txt === '' ? null : $txt;
    }
}