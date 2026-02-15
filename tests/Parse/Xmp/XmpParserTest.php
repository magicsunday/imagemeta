<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Xmp;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises the lightweight XMP parser across attribute and element extraction paths.
 * It covers RDF descriptions, nested containers (Alt/Bag/Seq), and namespace handling.
 * The suite verifies merged output when multiple descriptions contribute properties.
 * This ensures predictable XMP extraction for both simple and structured packets.
 */
#[CoversClass(XmpParser::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(XmpContainer::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(XmpLanguageAlternative::class)]
#[UsesClass(XmpStructuredValue::class)]
final class XmpParserTest extends TestCase
{
    private const string XMP_NS = 'http://ns.adobe.com/xap/1.0/';

    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    private const string EXIF_NS = 'http://ns.adobe.com/exif/1.0/';

    private const string TIFF_NS = 'http://ns.adobe.com/tiff/1.0/';

    private const string IPTC_CORE_NS = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';

    /**
     * Uses rdf:Description attributes for TIFF and XMP properties.
     * Confirms the parser captures attribute-based values across namespaces.
     *
     * @return void
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
     * Declares a custom namespace and provides a custom attribute property.
     * Ensures namespace declarations themselves are ignored as properties.
     *
     * @return void
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
     * Includes rdf:about alongside a dc:title attribute in the same description.
     * Verifies rdf:about is ignored while dc:title is captured.
     *
     * @return void
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
     * Treats xml:* attributes as qualifiers and does not expose them as standalone properties.
     *
     * @return void
     */
    #[Test]
    public function parseIgnoresXmlNamespaceAttributesAsStandaloneProperties(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description xml:lang="en-US" xml:space="preserve" dc:title="Test" />
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('Test', $document->get(self::DC_NS, 'title'));
        self::assertNull($document->find('lang'));
        self::assertNull($document->find('space'));
    }

    /**
     * Preserves rdf:Alt language qualifiers and default ordering.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesLanguageAlternatives(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title>
      <rdf:Alt>
        <rdf:li xml:lang="en-US">Hello</rdf:li>
        <rdf:li xml:lang="x-default">Default</rdf:li>
      </rdf:Alt>
    </dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);
        $alt      = $document->languageAlternative(self::DC_NS, 'title');

        self::assertInstanceOf(XmpLanguageAlternative::class, $alt);
        self::assertSame(['x-default', 'en-US'], $alt->languages());
        self::assertSame(['Default', 'Hello'], $alt->values());
        self::assertSame('Default', $alt->defaultValue());
        self::assertSame('Hello', $alt->valueFor('en-US'));
        self::assertNull($document->find('lang'));
    }

    /**
     * Preserves rdf:Bag container semantics in the parsed document model.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesBagContainerKind(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:subject>
      <rdf:Bag>
        <rdf:li>sunset</rdf:li>
        <rdf:li>vacation</rdf:li>
      </rdf:Bag>
    </dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame(XmpContainer::Bag, $document->containerType(self::DC_NS, 'subject'));
    }

    /**
     * Preserves rdf:Seq container semantics in the parsed document model.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesSeqContainerKind(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:creator>
      <rdf:Seq>
        <rdf:li>Alice</rdf:li>
        <rdf:li>Bob</rdf:li>
      </rdf:Seq>
    </dc:creator>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame(XmpContainer::Seq, $document->containerType(self::DC_NS, 'creator'));
    }

    /**
     * Preserves rdf:Alt container semantics in the parsed document model.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesAltContainerKind(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title>
      <rdf:Alt>
        <rdf:li xml:lang="x-default">Default</rdf:li>
        <rdf:li xml:lang="en-US">Hello</rdf:li>
      </rdf:Alt>
    </dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame(XmpContainer::Alt, $document->containerType(self::DC_NS, 'title'));
    }

    /**
     * Keeps simple text-property extraction unchanged with no container kind assigned.
     *
     * @return void
     */
    #[Test]
    public function parseSimpleTextPropertyHasNoContainerKind(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:xmp="http://ns.adobe.com/xap/1.0/">
  <rdf:Description>
    <xmp:CreateDate>2024-02-18T12:34:56Z</xmp:CreateDate>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('2024-02-18T12:34:56Z', $document->get(self::XMP_NS, 'CreateDate'));
        self::assertNull($document->containerType(self::XMP_NS, 'CreateDate'));
    }

    /**
     * Preserves value text when xml:lang is used as qualifier on a simple property.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesSimpleValueWithXmlLangQualifier(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title xml:lang="en-US">Hello</dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('Hello', $document->get(self::DC_NS, 'title'));
        self::assertNull($document->find('lang'));
    }

    /**
     * Preserves explicit empty scalar text values.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesEmptyScalarText(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title />
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('', $document->get(self::DC_NS, 'title'));
    }

    /**
     * Preserves empty element text with separate open and close tags.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesEmptyElementText(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title></dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('', $document->get(self::DC_NS, 'title'));
    }

    /**
     * Preserves empty attribute property values.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesEmptyAttributeValues(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description dc:title="" />
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('', $document->get(self::DC_NS, 'title'));
    }

    /**
     * Verifies that empty values do not interfere with non-empty values or structural filtering.
     *
     * @return void
     */
    #[Test]
    public function parseHandlesEmptyAndNonEmptyValuesMixed(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:xmp="http://ns.adobe.com/xap/1.0/">
  <rdf:Description rdf:about="" dc:title="" xmp:Rating="5">
    <dc:description></dc:description>
    <xmp:Label>Red</xmp:Label>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Empty values are preserved
        self::assertSame('', $document->get(self::DC_NS, 'title'));
        self::assertSame('', $document->get(self::DC_NS, 'description'));

        // Non-empty values are captured
        self::assertSame('5', $document->get(self::XMP_NS, 'Rating'));
        self::assertSame('Red', $document->get(self::XMP_NS, 'Label'));

        // RDF structural attributes are still ignored
        self::assertNull($document->find('about'));
    }

    /**
     * Preserves explicit empty list items in RDF containers.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesEmptyListItems(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:subject>
      <rdf:Seq>
        <rdf:li></rdf:li>
        <rdf:li>Two</rdf:li>
      </rdf:Seq>
    </dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame(['', 'Two'], $document->get(self::DC_NS, 'subject'));
    }

    /**
     * Provides multiple attributes in a custom drone-dji namespace.
     * Confirms the parser captures each custom attribute with its namespace URI.
     *
     * @return void
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
     * Mixes attribute-based values with element content inside the same RDF description.
     * Ensures the parser extracts both the attribute and element values.
     *
     * @return void
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
     * Uses rdf:value elements and an rdf:Bag list within resource nodes.
     * Verifies the parser resolves rdf:value and collects list items into arrays.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsValuesFromRdfValueElements(): void
    {
        $xml = <<<'XML'
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
      xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
      xmlns:dc="http://purl.org/dc/elements/1.1/">
      <tiff:Model rdf:parseType="Resource">
        <rdf:value>GT-I9195</rdf:value>
      </tiff:Model>
      <tiff:Orientation rdf:parseType="Resource">
        <rdf:value>1</rdf:value>
      </tiff:Orientation>
      <dc:subject>
        <rdf:Bag>
          <rdf:li rdf:parseType="Resource">
            <rdf:value>Portrait</rdf:value>
          </rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('GT-I9195', $document->get(self::TIFF_NS, 'Model'));
        self::assertSame('1', $document->get(self::TIFF_NS, 'Orientation'));
        self::assertSame(['Portrait'], $document->get(self::DC_NS, 'subject'));
    }

    /**
     * Preserves parseType Resource child fields under their parent property as a structured value.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsParseTypeResourceAsStructuredProperty(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">
  <rdf:Description>
    <Iptc4xmpCore:CreatorContactInfo rdf:parseType="Resource">
      <Iptc4xmpCore:CiEmailWork>jane@example.com</Iptc4xmpCore:CiEmailWork>
      <Iptc4xmpCore:CiTelWork>+49 30 555</Iptc4xmpCore:CiTelWork>
    </Iptc4xmpCore:CreatorContactInfo>
  </rdf:Description>
</rdf:RDF>
XML;

        $document    = (new XmpParser())->parse($xml);
        $contactInfo = $document->get(self::IPTC_CORE_NS, 'CreatorContactInfo');

        self::assertInstanceOf(XmpStructuredValue::class, $contactInfo);
        self::assertSame('jane@example.com', $contactInfo->get(self::IPTC_CORE_NS, 'CiEmailWork'));
        self::assertSame('+49 30 555', $contactInfo->get(self::IPTC_CORE_NS, 'CiTelWork'));

        self::assertNull($document->get(self::IPTC_CORE_NS, 'CiEmailWork'));
        self::assertNull($document->get(self::IPTC_CORE_NS, 'CiTelWork'));
    }

    /**
     * Keeps nested parseType Resource nodes as nested structured values.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsNestedParseTypeResourceStructure(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">
  <rdf:Description>
    <Iptc4xmpCore:CreatorContactInfo rdf:parseType="Resource">
      <Iptc4xmpCore:CiAdrCity>Berlin</Iptc4xmpCore:CiAdrCity>
      <Iptc4xmpCore:CiAdrExtadr rdf:parseType="Resource">
        <Iptc4xmpCore:Street>Main Street 1</Iptc4xmpCore:Street>
        <Iptc4xmpCore:HouseNumber>5</Iptc4xmpCore:HouseNumber>
      </Iptc4xmpCore:CiAdrExtadr>
    </Iptc4xmpCore:CreatorContactInfo>
  </rdf:Description>
</rdf:RDF>
XML;

        $document    = (new XmpParser())->parse($xml);
        $contactInfo = $document->get(self::IPTC_CORE_NS, 'CreatorContactInfo');

        self::assertInstanceOf(XmpStructuredValue::class, $contactInfo);
        self::assertSame('Berlin', $contactInfo->get(self::IPTC_CORE_NS, 'CiAdrCity'));

        $address = $contactInfo->get(self::IPTC_CORE_NS, 'CiAdrExtadr');
        self::assertInstanceOf(XmpStructuredValue::class, $address);
        self::assertSame('Main Street 1', $address->get(self::IPTC_CORE_NS, 'Street'));
        self::assertSame('5', $address->get(self::IPTC_CORE_NS, 'HouseNumber'));
    }

    /**
     * Keeps simple text and RDF container extraction unchanged when parseType Resource is present.
     *
     * @return void
     */
    #[Test]
    public function parseKeepsSimpleAndContainerPropertiesWhenParseTypeResourceExists(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
         xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">
  <rdf:Description>
    <tiff:Make>DJI</tiff:Make>
    <dc:subject>
      <rdf:Seq>
        <rdf:li>one</rdf:li>
        <rdf:li>two</rdf:li>
      </rdf:Seq>
    </dc:subject>
    <Iptc4xmpCore:CreatorContactInfo rdf:parseType="Resource">
      <Iptc4xmpCore:CiUrlWork>https://example.com</Iptc4xmpCore:CiUrlWork>
    </Iptc4xmpCore:CreatorContactInfo>
  </rdf:Description>
</rdf:RDF>
XML;

        $document = (new XmpParser())->parse($xml);

        self::assertSame('DJI', $document->get(self::TIFF_NS, 'Make'));
        self::assertSame(['one', 'two'], $document->get(self::DC_NS, 'subject'));

        $contactInfo = $document->get(self::IPTC_CORE_NS, 'CreatorContactInfo');
        self::assertInstanceOf(XmpStructuredValue::class, $contactInfo);
        self::assertSame('https://example.com', $contactInfo->get(self::IPTC_CORE_NS, 'CiUrlWork'));
    }

    /**
     * Parses a full EXIF/TIFF XMP sample with many tags across two descriptions.
     * Confirms all expected properties and namespace prefixes are captured.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsCompleteExifAndTiffSample(): void
    {
        $xml = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 4.4.0">
   <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
      <rdf:Description rdf:about=""
            xmlns:exif="http://ns.adobe.com/exif/1.0/">
         <exif:ExposureTime>0.020000</exif:ExposureTime>
         <exif:ExposureProgram>3</exif:ExposureProgram>
         <exif:DateTimeOriginal>2015:11:10 20:18:59</exif:DateTimeOriginal>
         <exif:DateTimeDigitized>2015:11:10 20:18:59</exif:DateTimeDigitized>
         <exif:ShutterSpeedValue>0.020000</exif:ShutterSpeedValue>
         <exif:BrightnessValue>76.000000</exif:BrightnessValue>
         <exif:ExposureBiasValue>0.000000</exif:ExposureBiasValue>
         <exif:MeteringMode>2</exif:MeteringMode>
         <exif:LightSource>0</exif:LightSource>
         <exif:ColorSpace>1</exif:ColorSpace>
         <exif:PixelXDimension>3264</exif:PixelXDimension>
         <exif:PixelYDimension>2448</exif:PixelYDimension>
         <exif:SensingMethod>2</exif:SensingMethod>
         <exif:ExposureMode>0</exif:ExposureMode>
         <exif:WhiteBalance>0</exif:WhiteBalance>
         <exif:DigitalZoomRatio>1.000000</exif:DigitalZoomRatio>
         <exif:SceneCaptureType>0</exif:SceneCaptureType>
         <exif:Saturation>0</exif:Saturation>
         <exif:Sharpness>0</exif:Sharpness>
      </rdf:Description>
      <rdf:Description rdf:about=""
            xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
         <tiff:Compression>6</tiff:Compression>
         <tiff:Make>SAMSUNG</tiff:Make>
         <tiff:Model>GT-I9195</tiff:Model>
         <tiff:Orientation>1</tiff:Orientation>
         <tiff:XResolution>72.000000</tiff:XResolution>
         <tiff:YResolution>72.000000</tiff:YResolution>
         <tiff:ResolutionUnit>2</tiff:ResolutionUnit>
      </rdf:Description>
   </rdf:RDF>
</x:xmpmeta>
XML;

        $document = (new XmpParser())->parse($xml);

        $expectedExif = [
            'ExposureTime'      => '0.020000',
            'ExposureProgram'   => '3',
            'DateTimeOriginal'  => '2015:11:10 20:18:59',
            'DateTimeDigitized' => '2015:11:10 20:18:59',
            'ShutterSpeedValue' => '0.020000',
            'BrightnessValue'   => '76.000000',
            'ExposureBiasValue' => '0.000000',
            'MeteringMode'      => '2',
            'LightSource'       => '0',
            'ColorSpace'        => '1',
            'PixelXDimension'   => '3264',
            'PixelYDimension'   => '2448',
            'SensingMethod'     => '2',
            'ExposureMode'      => '0',
            'WhiteBalance'      => '0',
            'DigitalZoomRatio'  => '1.000000',
            'SceneCaptureType'  => '0',
            'Saturation'        => '0',
            'Sharpness'         => '0',
        ];

        foreach ($expectedExif as $tag => $value) {
            self::assertSame($value, $document->get(self::EXIF_NS, $tag));
        }

        $expectedTiff = [
            'Compression'    => '6',
            'Make'           => 'SAMSUNG',
            'Model'          => 'GT-I9195',
            'Orientation'    => '1',
            'XResolution'    => '72.000000',
            'YResolution'    => '72.000000',
            'ResolutionUnit' => '2',
        ];

        foreach ($expectedTiff as $tag => $value) {
            self::assertSame($value, $document->get(self::TIFF_NS, $tag));
        }

        self::assertArrayHasKey(self::EXIF_NS, $document->namespacePrefixes);
        self::assertSame('exif', $document->namespacePrefixes[self::EXIF_NS]);
        self::assertArrayHasKey(self::TIFF_NS, $document->namespacePrefixes);
        self::assertSame('tiff', $document->namespacePrefixes[self::TIFF_NS]);
    }

    /**
     * Includes scalar values alongside a dc:subject rdf:Bag list.
     * Ensures scalar properties are strings and bag entries are returned as arrays.
     *
     * @return void
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
     * Feeds malformed XML fragments via the data provider.
     * Verifies the parser returns an empty document instead of throwing.
     *
     * @return void
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
     * This checks the behavior for the specific inputs used in the test.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideInvalidXmpFragments(): iterable
    {
        yield 'broken xml declaration' => ['<?xml version="1.0"?><rdf:RDF'];
    }

    /**
     * Uses a default namespace with an unprefixed element.
     * Confirms the parser stores the value under the namespace URI.
     *
     * @return void
     */
    #[Test]
    public function parseCapturesValuesFromGenericNamespaces(): void
    {
        $xml = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns="urn:example"><value>captured</value></rdf:Description>'
            . '</rdf:RDF>';

        $document = (new XmpParser())->parse($xml);

        self::assertSame('captured', $document->get('urn:example', 'value'));
    }

    /**
     * Records prefixed namespace declarations as URI-to-prefix mappings.
     *
     * @return void
     */
    #[Test]
    public function extractsPrefixedNamespaceMapping(): void
    {
        $xml = '<root xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>ok</dc:title></root>';

        $document = (new XmpParser())->parse($xml);

        self::assertArrayHasKey(self::DC_NS, $document->namespacePrefixes);
        self::assertSame('dc', $document->namespacePrefixes[self::DC_NS]);
    }

    /**
     * Records default xmlns declarations with an empty prefix marker, not "xmlns".
     *
     * @return void
     */
    #[Test]
    public function extractsDefaultNamespaceMappingWithEmptyPrefix(): void
    {
        $xml = '<root xmlns="urn:default"><value>ok</value></root>';

        $document = (new XmpParser())->parse($xml);

        self::assertArrayHasKey('urn:default', $document->namespacePrefixes);
        self::assertSame('', $document->namespacePrefixes['urn:default']);
    }

    /**
     * Keeps prefixed mappings unchanged when default and prefixed declarations coexist.
     *
     * @return void
     */
    #[Test]
    public function keepsPrefixedMappingsWhenDefaultNamespaceExists(): void
    {
        $xml = '<root xmlns="urn:default" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>ok</dc:title>'
            . '</root>';

        $document = (new XmpParser())->parse($xml);

        self::assertArrayHasKey('urn:default', $document->namespacePrefixes);
        self::assertSame('', $document->namespacePrefixes['urn:default']);
        self::assertArrayHasKey(self::DC_NS, $document->namespacePrefixes);
        self::assertSame('dc', $document->namespacePrefixes[self::DC_NS]);
    }

    /**
     * Mixes text nodes with a CDATA section inside a single element.
     * Ensures the parser concatenates mixed content into one string.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesMixedTextAndCdata(): void
    {
        $xml = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>Prefix <![CDATA[<tag> & middle]]> suffix</dc:title>'
            . '</rdf:Description>'
            . '</rdf:RDF>';

        $document = (new XmpParser())->parse($xml);

        $key = '{' . self::DC_NS . '}title';
        self::assertSame('Prefix <tag> & middle suffix', $document->get(self::DC_NS, 'title'));
        self::assertArrayHasKey($key, $document->data);
        self::assertSame('Prefix <tag> & middle suffix', $document->find('title'));
    }

    /**
     * Parses metadata with many custom namespaces and attribute values.
     * Verifies values are captured and namespace prefixes are recorded for each URI.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsMultipleCustomNamespaces(): void
    {
        $xml = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/">
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
<rdf:Description rdf:about="DJI Meta Data"
xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
xmlns:xmp="http://ns.adobe.com/xap/1.0/"
xmlns:dc="http://purl.org/dc/elements/1.1/"
xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"
xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/"
xmlns:GPano="http://ns.google.com/photos/1.0/panorama/"
xmlns:Camera="http://pix4d.com/camera/1.0"
xmp:ModifyDate="2025-04-20T12:10:18+02:00"
tiff:Make="DJI"
tiff:Model="FC8671"
dc:format="image/jpeg"
drone-dji:Version="1.6"
drone-dji:GpsLatitude="+51.242990270"
drone-dji:ProductName="NEO"
Camera:FileType="single"
crs:Version="7.0"
crs:HasSettings="False"
GPano:ProjectionType="equirectangular"
>
</rdf:Description>
</rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Validate DJI drone namespace
        $djiNs = 'http://www.dji.com/drone-dji/1.0/';
        self::assertSame('1.6', $document->get($djiNs, 'Version'));
        self::assertSame('+51.242990270', $document->get($djiNs, 'GpsLatitude'));
        self::assertSame('NEO', $document->get($djiNs, 'ProductName'));

        // Validate Pix4D Camera namespace
        $cameraNs = 'http://pix4d.com/camera/1.0';
        self::assertSame('single', $document->get($cameraNs, 'FileType'));

        // Validate Adobe Camera Raw Settings namespace
        $crsNs = 'http://ns.adobe.com/camera-raw-settings/1.0/';
        self::assertSame('7.0', $document->get($crsNs, 'Version'));
        self::assertSame('False', $document->get($crsNs, 'HasSettings'));

        // Validate Google Panorama namespace
        $gpanoNs = 'http://ns.google.com/photos/1.0/panorama/';
        self::assertSame('equirectangular', $document->get($gpanoNs, 'ProjectionType'));

        // Also validate standard namespaces still work
        self::assertSame('2025-04-20T12:10:18+02:00', $document->get(self::XMP_NS, 'ModifyDate'));
        self::assertSame('DJI', $document->get(self::TIFF_NS, 'Make'));
        self::assertSame('FC8671', $document->get(self::TIFF_NS, 'Model'));
        self::assertSame('image/jpeg', $document->get(self::DC_NS, 'format'));

        // Validate that namespace prefixes were extracted correctly
        self::assertArrayHasKey($djiNs, $document->namespacePrefixes);
        self::assertSame('drone-dji', $document->namespacePrefixes[$djiNs]);

        self::assertArrayHasKey($cameraNs, $document->namespacePrefixes);
        self::assertSame('Camera', $document->namespacePrefixes[$cameraNs]);

        self::assertArrayHasKey($crsNs, $document->namespacePrefixes);
        self::assertSame('crs', $document->namespacePrefixes[$crsNs]);

        self::assertArrayHasKey($gpanoNs, $document->namespacePrefixes);
        self::assertSame('GPano', $document->namespacePrefixes[$gpanoNs]);

        // Validate standard namespace prefixes
        self::assertArrayHasKey(self::XMP_NS, $document->namespacePrefixes);
        self::assertSame('xmp', $document->namespacePrefixes[self::XMP_NS]);

        self::assertArrayHasKey(self::TIFF_NS, $document->namespacePrefixes);
        self::assertSame('tiff', $document->namespacePrefixes[self::TIFF_NS]);

        self::assertArrayHasKey(self::DC_NS, $document->namespacePrefixes);
        self::assertSame('dc', $document->namespacePrefixes[self::DC_NS]);
    }

    /**
     * Uses the Google depthmap namespace with depth-related attributes.
     * Ensures the parser extracts data, mime, and near/far distances.
     *
     * @return void
     */
    #[Test]
    public function parseExtractsDepthMapProperties(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:GDepth="http://ns.google.com/photos/1.0/depthmap/">
  <rdf:Description
    GDepth:Data="ZGVwdGg="
    GDepth:Mime="image/png"
    GDepth:Near="0.25"
    GDepth:Far="10.5"
  />
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        $depthNs = 'http://ns.google.com/photos/1.0/depthmap/';

        self::assertSame('ZGVwdGg=', $document->get($depthNs, 'Data'));
        self::assertSame('image/png', $document->get($depthNs, 'Mime'));
        self::assertSame('0.25', $document->get($depthNs, 'Near'));
        self::assertSame('10.5', $document->get($depthNs, 'Far'));
    }

    /**
     * Trims XML structural whitespace from simple text values while preserving inner content.
     *
     * @return void
     */
    #[Test]
    public function parseTrimXmlWhitespaceFromTextValues(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title>  padded value  </dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('padded value', $document->get(self::DC_NS, 'title'));
    }

    /**
     * Filters rdf:resource and rdf:datatype as structural attributes.
     *
     * @return void
     */
    #[Test]
    public function parseFiltersRdfResourceAndDatatypeAttributes(): void
    {
        $xml = <<<'XML_WRAP'
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:dc="http://purl.org/dc/elements/1.1/">
              <rdf:Description>
                <dc:format rdf:resource="http://example.com/resource" rdf:datatype="http://www.w3.org/2001/XMLSchema#string">image/jpeg</dc:format>
              </rdf:Description>
            </rdf:RDF>
            XML_WRAP;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Should capture the element text value
        self::assertSame('image/jpeg', $document->get(self::DC_NS, 'format'));

        // Should NOT capture rdf:resource or rdf:datatype as properties
        self::assertNull($document->find('resource'));
        self::assertNull($document->find('datatype'));
    }

    /**
     * Preserves empty rdf:li items within RDF Bag containers.
     *
     * @return void
     */
    #[Test]
    public function parsePreservesEmptyRdfLiItems(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:subject>
      <rdf:Bag>
        <rdf:li>first</rdf:li>
        <rdf:li></rdf:li>
        <rdf:li>third</rdf:li>
      </rdf:Bag>
    </dc:subject>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame(['first', '', 'third'], $document->get(self::DC_NS, 'subject'));
    }

    /**
     * Rejects rdf:Alt with duplicate xml:lang values per XMP spec.
     *
     * @return void
     */
    #[Test]
    public function rejectsDuplicateXmlLangInAlt(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title>
      <rdf:Alt>
        <rdf:li xml:lang="en-US">First</rdf:li>
        <rdf:li xml:lang="en-US">Duplicate</rdf:li>
      </rdf:Alt>
    </dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser = new XmpParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(ParseError::XMP_ALT_DUPLICATE_LANG);
        $this->expectExceptionMessage('Duplicate xml:lang "en-US" in rdf:Alt');

        $parser->parse($xml);
    }

    /**
     * Rejects rdf:li in rdf:Alt without xml:lang qualifier.
     *
     * @return void
     */
    #[Test]
    public function rejectsMissingXmlLangInAlt(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:Description>
    <dc:title>
      <rdf:Alt>
        <rdf:li xml:lang="x-default">Default</rdf:li>
        <rdf:li>No language</rdf:li>
      </rdf:Alt>
    </dc:title>
  </rdf:Description>
</rdf:RDF>
XML;

        $parser = new XmpParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('rdf:li in rdf:Alt must have an xml:lang qualifier');

        $parser->parse($xml);
    }

    /**
     * XML payload without rdf:RDF returns an empty document.
     *
     * ISO 16684-1: XMP metadata is expressed within an rdf:RDF graph.
     * Non-RDF XML payloads must not produce false-positive property extraction.
     *
     * @return void
     */
    #[Test]
    public function returnsEmptyDocumentWithoutRdfGraph(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<root xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
  <tiff:Make>FakeCamera</tiff:Make>
</root>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertNull($document->get(self::TIFF_NS, 'Make'));
    }

    /**
     * Payload with extra XML outside rdf:RDF ignores outside nodes.
     *
     * Only properties within the rdf:RDF graph are extracted; elements
     * in wrapper nodes like x:xmpmeta are not treated as XMP properties.
     *
     * @return void
     */
    #[Test]
    public function ignoresPropertiesOutsideRdfGraph(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 5.0">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description xmlns:tiff="http://ns.adobe.com/tiff/1.0/" tiff:Make="Canon"/>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Property inside rdf:RDF is extracted
        self::assertSame('Canon', $document->get(self::TIFF_NS, 'Make'));

        // x:xmptk on wrapper element outside rdf:RDF is not extracted
        self::assertNull($document->get('adobe:ns:meta/', 'xmptk'));
    }

    /**
     * Valid packet with x:xmpmeta + rdf:RDF still parses correctly (regression).
     *
     * @return void
     */
    #[Test]
    public function parsesValidXmpPacketWithXmpmeta(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description
      xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
      xmlns:xmp="http://ns.adobe.com/xap/1.0/"
      tiff:Make="Nikon"
      xmp:CreatorTool="Test"
    />
  </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('Nikon', $document->get(self::TIFF_NS, 'Make'));
        self::assertSame('Test', $document->get(self::XMP_NS, 'CreatorTool'));
    }

    /**
     * Returns nearest list container metadata for the given element depth.
     *
     * @return void
     */
    #[Test]
    public function findParentListBufferReturnsNearestListContext(): void
    {
        $method = new ReflectionMethod(XmpParser::class, 'findParentListBuffer');
        $result = $method->invoke(
            new XmpParser(),
            [1 => ['root'], 3 => ['child']],
            [1 => 'Bag', 3 => 'Alt'],
            5,
            'en-US',
        );

        self::assertSame(
            [
                'depth' => 3,
                'kind'  => 'Alt',
            ],
            $result,
        );
    }

    /**
     * Returns null when no parent list container exists.
     *
     * @return void
     */
    #[Test]
    public function findParentListBufferReturnsNullWithoutParentList(): void
    {
        $method = new ReflectionMethod(XmpParser::class, 'findParentListBuffer');
        $result = $method->invoke(
            new XmpParser(),
            [],
            [],
            3,
            '',
        );

        self::assertNull($result);
    }

    /**
     * Rejects rdf:Alt list entries without xml:lang in helper validation.
     *
     * @return void
     */
    #[Test]
    public function validateAltContainerLangRejectsMissingLanguageQualifier(): void
    {
        $method = new ReflectionMethod(XmpParser::class, 'validateAltContainerLang');

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(ParseError::XMP_ALT_MISSING_LANG);
        $this->expectExceptionMessage('rdf:li in rdf:Alt must have an xml:lang qualifier');

        $method->invoke(new XmpParser(), 'Alt', '');
    }
}
