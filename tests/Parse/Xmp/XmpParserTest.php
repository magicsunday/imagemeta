<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates the lightweight streaming XMP parser implementation.
 * */
#[CoversClass(XmpParser::class)]
#[UsesClass(XmpDocument::class)]
final class XmpParserTest extends TestCase
{
    private const string XMP_NS = 'http://ns.adobe.com/xap/1.0/';

    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    private const string EXIF_NS = 'http://ns.adobe.com/exif/1.0/';

    private const string TIFF_NS = 'http://ns.adobe.com/tiff/1.0/';

    /**
     * Ensures attributes on rdf:Description are captured as properties.
     *
     * XMP Specification Part 1 §7.9.2.2 allows properties to be encoded as
     * attributes on rdf:Description elements, which is commonly used for simple
     * scalar values. This test validates that such attributes are correctly
     * extracted and stored using Clark notation.
     */
    #[Test]
    public function parseExtractsAttributeProperties(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description
      xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
      xmlns:xmp="http://ns.adobe.com/xap/1.0/"
      tiff:Make="DJI"
      tiff:Model="FC8671"
      xmp:ModifyDate="2025-04-20T12:10:18+02:00"
    />
  </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('DJI', $document->get(self::TIFF_NS, 'Make'));
        self::assertSame('FC8671', $document->get(self::TIFF_NS, 'Model'));
        self::assertSame('2025-04-20T12:10:18+02:00', $document->get(self::XMP_NS, 'ModifyDate'));
    }

    /**
     * Ensures namespace declaration attributes (xmlns:*) are not captured as properties.
     *
     * XMP Specification Part 1 §7.2 defines namespace declarations as XML metadata
     * that should not be treated as XMP properties.
     */
    #[Test]
    public function parseIgnoresNamespaceDeclarations(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:custom="http://example.com/custom/">
  <rdf:Description custom:property="value" />
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Should capture the custom property
        self::assertSame('value', $document->get('http://example.com/custom/', 'property'));

        // Should NOT capture namespace declarations as properties
        self::assertNull($document->find('rdf'));
        self::assertNull($document->find('custom'));
    }

    /**
     * Ensures rdf:about and similar RDF structural attributes are not captured.
     *
     * XMP Specification Part 1 §7.9.2.2 specifies that rdf:about, rdf:ID, and
     * rdf:nodeID are RDF structural attributes that should not be treated as XMP properties.
     */
    #[Test]
    public function parseIgnoresRdfStructuralAttributes(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description rdf:about="Some Resource" dc:title="Test" />
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Should capture the dc:title property
        self::assertSame('Test', $document->get(self::DC_NS, 'title'));

        // Should NOT capture rdf:about
        self::assertNull($document->find('about'));
    }

    /**
     * Ensures attributes from custom namespaces (like drone-dji) are captured correctly.
     *
     * This test validates real-world XMP data from DJI drones, which store extensive
     * metadata as attributes in custom namespaces.
     */
    #[Test]
    public function parseExtractsCustomNamespaceAttributes(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/">
  <rdf:Description
    drone-dji:GpsLatitude="+51.242990270"
    drone-dji:GpsLongitude="+12.794229252"
    drone-dji:AbsoluteAltitude="+375.338"
    drone-dji:ProductName="NEO"
  />
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        $djiNs = 'http://www.dji.com/drone-dji/1.0/';

        self::assertSame('+51.242990270', $document->get($djiNs, 'GpsLatitude'));
        self::assertSame('+12.794229252', $document->get($djiNs, 'GpsLongitude'));
        self::assertSame('+375.338', $document->get($djiNs, 'AbsoluteAltitude'));
        self::assertSame('NEO', $document->get($djiNs, 'ProductName'));
    }

    /**
     * Ensures both attribute and element child values coexist correctly.
     *
     * XMP Specification Part 1 §7.9.2.2 allows mixing attribute and element
     * representations within the same rdf:Description.
     */
    #[Test]
    public function parseExtractsMixedAttributesAndElements(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:xmp="http://ns.adobe.com/xap/1.0/"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description
    xmp:ModifyDate="2024-03-30T12:00:00Z"
  >
    <dc:title>Element Value</dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Attribute value
        self::assertSame('2024-03-30T12:00:00Z', $document->get(self::XMP_NS, 'ModifyDate'));

        // Element value
        self::assertSame('Element Value', $document->get(self::DC_NS, 'title'));
    }

    /**
     * Ensures scalar properties and rdf:Bag containers are extracted correctly.
     */
    #[Test]
    public function parseExtractsScalarAndBagValues(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description
      xmlns:xmp="http://ns.adobe.com/xap/1.0/"
      xmlns:dc="http://purl.org/dc/elements/1.1/"
      xmlns:exif="http://ns.adobe.com/exif/1.0/"
    >
      <xmp:CreateDate>2024-02-18T12:34:56Z</xmp:CreateDate>
      <exif:DateTimeOriginal>2024-02-18T12:34:56</exif:DateTimeOriginal>
      <dc:subject>
        <rdf:Bag>
          <rdf:li>sunset</rdf:li>
          <rdf:li>vacation</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame(
            '2024-02-18T12:34:56Z',
            $document->get(self::XMP_NS, 'CreateDate')
        );
        self::assertSame(
            '2024-02-18T12:34:56',
            $document->get(self::EXIF_NS, 'DateTimeOriginal')
        );
        self::assertSame(
            ['sunset', 'vacation'],
            $document->get(self::DC_NS, 'subject')
        );
    }

    /**
     * Ensures malformed or unsupported XML fragments result in an empty document.
     */
    #[Test]
    #[DataProvider('provideInvalidXmpFragments')]
    public function parseReturnsEmptyDocumentForInvalidXml(string $xml): void
    {
        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame([], $document->data);
    }

    /**
     * Provides malformed or unsupported XML fragments for negative parsing scenarios.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideInvalidXmpFragments(): iterable
    {
        yield 'broken xml declaration' => ['<?xml version="1.0"?><rdf:RDF'];
    }

    /**
     * Ensures elements from arbitrary namespaces are preserved using Clark notation.
     */
    #[Test]
    public function parseCapturesValuesFromGenericNamespaces(): void
    {
        $xml = '<root xmlns="urn:example"><value>captured</value></root>';

        $document = (new XmpParser())->parse($xml);

        self::assertSame('captured', $document->get('urn:example', 'value'));
    }

    /**
     * Ensures mixed text nodes and CDATA sections are concatenated verbatim.
     */
    #[Test]
    public function parsePreservesMixedTextAndCdata(): void
    {
        $xml = '<dc:title xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . 'Prefix <![CDATA[<tag> & middle]]> suffix'
            . '</dc:title>';

        $document = (new XmpParser())->parse($xml);

        $key = '{' . self::DC_NS . '}title';
        self::assertSame('Prefix <tag> & middle suffix', $document->get(self::DC_NS, 'title'));
        self::assertArrayHasKey($key, $document->data);
        self::assertSame('Prefix <tag> & middle suffix', $document->find('title'));
    }
}
