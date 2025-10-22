<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Minimaler XMP RDF/XML Parser via \XMLReader (streaming‑nah):
 *  - liest Dublin Core (dc:subject), xmp:*, exif:*, tiff:* u. a. simple text properties
 *  - ignoriert komplexe Strukturen zunächst (können iterativ ergänzt werden)
 */
final class XmpReader
{
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

    private function qname(\XMLReader $xr): string
    {
        $prefix = $xr->prefix !== '' ? $xr->prefix . ':' : '';
        return $prefix . $xr->localName;
    }

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