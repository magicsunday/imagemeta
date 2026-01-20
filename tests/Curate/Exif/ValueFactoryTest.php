<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif;

use MagicSunday\ImageMeta\Curate\Exif\ValueFactory;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueFactory::class)]
#[UsesClass(Author::class)]
#[UsesClass(ExifAssembler::class)]
#[UsesClass(Image::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(DepthMap::class)]
final class ValueFactoryTest extends TestCase
{
    #[Test]
    public function assemblesDepthMapFromXmpPacket(): void
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

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: (new XmpParser())->parse($xml),
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertInstanceOf(DepthMap::class, $structured->depthMap);
        self::assertSame('ZGVwdGg=', $structured->depthMap->data);
        self::assertSame('image/png', $structured->depthMap->mime);
        self::assertSame(0.25, $structured->depthMap->near);
        self::assertSame(10.5, $structured->depthMap->far);
    }

    #[Test]
    public function mapsXmpCreatorContactInfoAndTitles(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"
         xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">
  <rdf:Description>
    <dc:title>
      <rdf:Alt>
        <rdf:li xml:lang="x-default">Sample Title</rdf:li>
      </rdf:Alt>
    </dc:title>
    <dc:description>
      <rdf:Alt>
        <rdf:li xml:lang="x-default">Sample Description</rdf:li>
      </rdf:Alt>
    </dc:description>
    <photoshop:Headline>Fallback Headline</photoshop:Headline>
    <Iptc4xmpCore:CreatorContactInfo rdf:parseType="Resource">
      <Iptc4xmpCore:CiEmailWork>jane@example.com</Iptc4xmpCore:CiEmailWork>
      <Iptc4xmpCore:CiTelWork>+49 30 555</Iptc4xmpCore:CiTelWork>
      <Iptc4xmpCore:CiAdrExtadr>Main Street 1</Iptc4xmpCore:CiAdrExtadr>
      <Iptc4xmpCore:CiAdrCity>Berlin</Iptc4xmpCore:CiAdrCity>
      <Iptc4xmpCore:CiAdrRegion>BE</Iptc4xmpCore:CiAdrRegion>
      <Iptc4xmpCore:CiAdrPcode>10115</Iptc4xmpCore:CiAdrPcode>
      <Iptc4xmpCore:CiAdrCtry>DE</Iptc4xmpCore:CiAdrCtry>
      <Iptc4xmpCore:CiUrlWork>https://example.com</Iptc4xmpCore:CiUrlWork>
    </Iptc4xmpCore:CreatorContactInfo>
  </rdf:Description>
</rdf:RDF>
XML;

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: (new XmpParser())->parse($xml),
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('Sample Title', $structured->image->title);
        self::assertSame('Sample Description', $structured->image->description);
        self::assertSame('jane@example.com', $structured->author->creatorEmail);
        self::assertSame('+49 30 555', $structured->author->creatorPhone);
        self::assertSame('Main Street 1', $structured->author->creatorAddress);
        self::assertSame('Berlin', $structured->author->creatorCity);
        self::assertSame('BE', $structured->author->creatorRegion);
        self::assertSame('10115', $structured->author->creatorPostalCode);
        self::assertSame('DE', $structured->author->creatorCountry);
        self::assertSame('https://example.com', $structured->author->creatorUrl);
    }
}
