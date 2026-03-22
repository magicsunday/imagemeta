<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpValueAccumulator;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParseState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the XMP parser with multiple rdf:Description blocks across namespaces.
 * It verifies that EXIF and TIFF properties are extracted even when split across descriptions.
 * The test asserts namespace prefixes are preserved and values are not dropped.
 * This guards against regressions where one namespace would overshadow another.
 */
#[CoversClass(XmpParser::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(XmpParseState::class)]
#[UsesClass(XmpContainer::class)]
#[UsesClass(XmpValueAccumulator::class)]
final class XmpParserCompleteExtractionTest extends TestCase
{
    private const string EXIF_NS = 'http://ns.adobe.com/exif/1.0/';

    private const string TIFF_NS = 'http://ns.adobe.com/tiff/1.0/';

    /**
     * Parses an XMP document with separate rdf:Description blocks for EXIF and TIFF namespaces.
     * Verifies the parser extracts all properties across descriptions and records namespace prefixes.
     */
    #[Test]
    public function parseExtractsAllPropertiesFromMultipleDescriptions(): void
    {
        $xml = <<<'XML'
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 4.4.0">
   <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
      <rdf:Description rdf:about=""
            xmlns:exif="http://ns.adobe.com/exif/1.0/">
         <exif:ExposureTime>0.020000</exif:ExposureTime>
         <exif:ExposureProgram>3</exif:ExposureProgram>
         <exif:DateTimeOriginal>2015:11:10 20:18:59</exif:DateTimeOriginal>
         <exif:MeteringMode>2</exif:MeteringMode>
         <exif:LightSource>0</exif:LightSource>
         <exif:ColorSpace>1</exif:ColorSpace>
         <exif:PixelXDimension>3264</exif:PixelXDimension>
         <exif:PixelYDimension>2448</exif:PixelYDimension>
         <exif:SensingMethod>2</exif:SensingMethod>
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

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // Verify EXIF namespace properties are extracted
        self::assertSame('0.020000', $document->get(self::EXIF_NS, 'ExposureTime'));
        self::assertSame('3', $document->get(self::EXIF_NS, 'ExposureProgram'));
        self::assertSame('2015:11:10 20:18:59', $document->get(self::EXIF_NS, 'DateTimeOriginal'));
        self::assertSame('2', $document->get(self::EXIF_NS, 'MeteringMode'));
        self::assertSame('0', $document->get(self::EXIF_NS, 'LightSource'));
        self::assertSame('1', $document->get(self::EXIF_NS, 'ColorSpace'));
        self::assertSame('3264', $document->get(self::EXIF_NS, 'PixelXDimension'));
        self::assertSame('2448', $document->get(self::EXIF_NS, 'PixelYDimension'));
        self::assertSame('2', $document->get(self::EXIF_NS, 'SensingMethod'));
        self::assertSame('0', $document->get(self::EXIF_NS, 'Saturation'));
        self::assertSame('0', $document->get(self::EXIF_NS, 'Sharpness'));

        // Verify ALL TIFF namespace properties are extracted (regression check)
        self::assertSame('6', $document->get(self::TIFF_NS, 'Compression'), 'Compression should be extracted');
        self::assertSame('SAMSUNG', $document->get(self::TIFF_NS, 'Make'), 'Make should be extracted');
        self::assertSame('GT-I9195', $document->get(self::TIFF_NS, 'Model'), 'Model should be extracted');
        self::assertSame('1', $document->get(self::TIFF_NS, 'Orientation'), 'Orientation should be extracted');
        self::assertSame('72.000000', $document->get(self::TIFF_NS, 'XResolution'), 'XResolution should be extracted');
        self::assertSame('72.000000', $document->get(self::TIFF_NS, 'YResolution'), 'YResolution should be extracted');
        self::assertSame('2', $document->get(self::TIFF_NS, 'ResolutionUnit'), 'ResolutionUnit should be extracted');

        // Verify namespace prefixes are captured
        self::assertArrayHasKey('http://ns.adobe.com/exif/1.0/', $document->namespacePrefixes);
        self::assertSame('exif', $document->namespacePrefixes['http://ns.adobe.com/exif/1.0/']);
        self::assertArrayHasKey('http://ns.adobe.com/tiff/1.0/', $document->namespacePrefixes);
        self::assertSame('tiff', $document->namespacePrefixes['http://ns.adobe.com/tiff/1.0/']);
    }

    /**
     * Attributes on x:xmpmeta wrapper are outside the rdf:RDF graph and
     * must not be extracted as XMP properties (ISO 16684-1).
     */
    #[Test]
    public function ignoresXmpMetaWrapperAttributes(): void
    {
        $xml = <<<'XML'
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 4.4.0">
   <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
      <rdf:Description rdf:about="" xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
         <tiff:Make>SAMSUNG</tiff:Make>
      </rdf:Description>
   </rdf:RDF>
</x:xmpmeta>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        // x:xmptk is outside rdf:RDF and must not be extracted
        self::assertNull($document->get('adobe:ns:meta/', 'xmptk'));

        // Properties inside rdf:RDF are still extracted
        self::assertSame('SAMSUNG', $document->get('http://ns.adobe.com/tiff/1.0/', 'Make'));
    }

    /**
     * Provides decimal-valued TIFF properties inside an RDF description.
     * Confirms the parser preserves decimal strings without normalization.
     */
    #[Test]
    public function parseExtractsDecimalValues(): void
    {
        $xml = <<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
   <rdf:Description xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
      <tiff:XResolution>72.000000</tiff:XResolution>
      <tiff:YResolution>96.500000</tiff:YResolution>
   </rdf:Description>
</rdf:RDF>
XML;

        $parser   = new XmpParser();
        $document = $parser->parse($xml);

        self::assertSame('72.000000', $document->get(self::TIFF_NS, 'XResolution'));
        self::assertSame('96.500000', $document->get(self::TIFF_NS, 'YResolution'));
    }
}
