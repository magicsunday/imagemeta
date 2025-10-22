<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use XMLReader;

/**
 * Lightweight streaming XMP (RDF/XML) parser via XMLReader.
 * - extrahiert gängige Properties (xmp:CreateDate, exif:DateTimeOriginal, dc:subject (Bag))
 * - belässt unbekannte Props als einfache textuelle Werte (Best-Effort)
 */
final class XmpParser
{
    public function parse(string $xmpXml): XmpDocument
    {
        $xr = new XMLReader();
        $xr->XML($xmpXml, encoding: 'UTF-8', options: XMLReader::SUBST_ENTITIES);

        $props = [];

        while ($xr->read()) {
            if ($xr->nodeType !== XMLReader::ELEMENT) continue;

            $qname = $this->qname($xr);
            if ($qname === 'rdf:Bag' && isset($props['_last_dc_subject'])) {
                // parse list items into dc:subject
                $props['dc:subject'] = $this->readBag($xr);
                unset($props['_last_dc_subject']);
                continue;
            }

            // capture strings for common props
            if (in_array($qname, [
                'xmp:CreateDate','xmp:ModifyDate',
                'exif:DateTimeOriginal','exif:OffsetTimeOriginal'
            ], true)) {
                $props[$qname] = $this->readElementText($xr);
            }

            if ($qname === 'dc:subject') {
                // the actual strings live in nested rdf:Bag/rdf:li
                $props['_last_dc_subject'] = true;
            }
        }

        $xr->close();
        return new XmpDocument($props);
    }

    private function qname(XMLReader $xr): string
    {
        $prefix = $xr->prefix ? $xr->prefix . ':' : '';
        return $prefix . $xr->localName;
    }

    private function readElementText(XMLReader $xr): string
    {
        $text = '';
        if (!$xr->isEmptyElement) {
            while ($xr->read()) {
                if ($xr->nodeType === XMLReader::TEXT || $xr->nodeType === XMLReader::CDATA) {
                    $text .= $xr->value;
                } elseif ($xr->nodeType === XMLReader::END_ELEMENT) {
                    break;
                }
            }
        }
        return trim($text);
    }

    /** @return list<string> */
    private function readBag(XMLReader $xr): array
    {
        $items = [];
        if ($xr->isEmptyElement) return $items;

        $depth = $xr->depth;
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->depth === $depth && $xr->localName === 'Bag') {
                break;
            }
            if ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'li') {
                $items[] = $this->readElementText($xr);
            }
        }
        return array_values(array_filter($items, fn($s) => $s !== ''));
    }
}
