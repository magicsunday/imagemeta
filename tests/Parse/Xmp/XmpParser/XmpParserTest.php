<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Xmp\XmpParser;

use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the lightweight streaming XMP parser implementation.
 *
 * @covers \MagicSunday\ImageMeta\Parse\Xmp\XmpParser
 */
final class XmpParserTest extends TestCase
{
    private const string XMP_NS = 'http://ns.adobe.com/xap/1.0/';

    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    private const string EXIF_NS = 'http://ns.adobe.com/exif/1.0/';

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
