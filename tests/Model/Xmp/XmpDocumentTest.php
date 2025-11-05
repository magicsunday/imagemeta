<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Integration-level tests for XmpDocument accessors fed by different parsers.
 */
#[CoversClass(XmpDocument::class)]
#[UsesClass(XmpParser::class)]
final class XmpDocumentTest extends TestCase
{
    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const string XMP_NAMESPACE = 'http://ns.adobe.com/xap/1.0/';

    private const string EXIF_NAMESPACE = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Verifies parser-sourced documents expose their values through accessors.
     */
    #[Test]
    public function documentAccessorsWithRdfFragment(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:xmp="http://ns.adobe.com/xap/1.0/">
  <rdf:Description>
    <xmp:CreateDate>2024-03-30T12:34:56Z</xmp:CreateDate>
    <dc:subject>
      <rdf:Bag>
        <rdf:li>First</rdf:li>
        <rdf:li>Second</rdf:li>
      </rdf:Bag>
    </dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('2024-03-30T12:34:56Z', $document->get(self::XMP_NAMESPACE, 'CreateDate'));
        self::assertSame('2024-03-30T12:34:56Z', $document->find('CreateDate'));
        self::assertSame(['First', 'Second'], $document->get(self::DC_NAMESPACE, 'subject'));
        $subjects = $document->find('subject');
        self::assertIsArray($subjects);
        self::assertSame(['First', 'Second'], $subjects);
        self::assertNull($document->get(self::DC_NAMESPACE, 'title'));
    }

    /**
     * Checks parser-generated documents return the expected accessor values.
     */
    #[Test]
    public function documentAccessorsWithParserData(): void
    {
        $xml = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/"
           xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
           xmlns:xmp="http://ns.adobe.com/xap/1.0/"
           xmlns:exif="http://ns.adobe.com/exif/1.0/"
           xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:RDF>
    <rdf:Description>
      <xmp:ModifyDate>2024-03-30T12:34:56Z</xmp:ModifyDate>
      <exif:DateTimeOriginal>2024-03-29T09:08:07Z</exif:DateTimeOriginal>
      <dc:subject>
        <rdf:Bag>
          <rdf:li>First</rdf:li>
          <rdf:li>Second</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('2024-03-30T12:34:56Z', $document->get(self::XMP_NAMESPACE, 'ModifyDate'));
        self::assertSame('2024-03-29T09:08:07Z', $document->get(self::EXIF_NAMESPACE, 'DateTimeOriginal'));
        self::assertSame('2024-03-29T09:08:07Z', $document->find('DateTimeOriginal'));
        self::assertSame(['First', 'Second'], $document->find('subject'));
    }

    /**
     * Ensures external entity bag entries are ignored to avoid unsafe values.
     */
    #[Test]
    public function externalEntityBagIsIgnored(): void
    {
        $xml = <<<XML
<!DOCTYPE rdf:RDF [
<!ENTITY ext SYSTEM "https://example.com/external">
]>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:xmp="http://ns.adobe.com/xap/1.0/">
  <rdf:Description>
    <xmp:ModifyDate>2024-04-01T00:00:00Z</xmp:ModifyDate>
    <dc:subject>
      <rdf:Bag>
        <rdf:li>&ext;</rdf:li>
      </rdf:Bag>
    </dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        $subjectKey = '{' . self::DC_NAMESPACE . '}subject';

        self::assertArrayHasKey('{' . self::XMP_NAMESPACE . '}ModifyDate', $document->data);
        self::assertArrayNotHasKey($subjectKey, $document->data);
        self::assertNull($document->find('subject'));
    }

    /**
     * Multiple occurrences of identical properties are returned as ordered lists.
     */
    #[Test]
    public function documentMergesRepeatedValues(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:subject>Alpha</dc:subject>
  </rdf:Description>
  <rdf:Description>
    <dc:subject>Beta</dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        $subjects = $document->get(self::DC_NAMESPACE, 'subject');

        self::assertIsArray($subjects);
        self::assertSame(['Alpha', 'Beta'], $subjects);
    }

    #[Test]
    public function stringListSplitsCommaSeparatedValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => 'Alpha, Beta , ,Gamma',
        ]);

        self::assertSame(['Alpha', 'Beta', 'Gamma'], $document->stringList(self::DC_NAMESPACE, 'subject'));
    }

    #[Test]
    public function stringListTrimsArrayValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => ['First', ' Second ', ''],
        ]);

        self::assertSame(['First', 'Second'], $document->stringList(self::DC_NAMESPACE, 'subject'));
    }
}
