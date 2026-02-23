<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use Closure;
use MagicSunday\ImageMeta\Exif\Factory\TiffDataFactory;
use MagicSunday\ImageMeta\Exif\Factory\ValueFactory;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Icc\IccParserInterface;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\CaptureHardware;
use MagicSunday\ImageMeta\Value\CaptureSettings;
use MagicSunday\ImageMeta\Value\CreatorContact;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Iptc;
use MagicSunday\ImageMeta\Value\LocationTime;
use MagicSunday\ImageMeta\Value\MediaContent;
use MagicSunday\ImageMeta\Value\Provenance;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\TechnicalData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function strlen;

/**
 * Exercises ValueFactory for assembling structured component arrays from Metadata.
 * It verifies XMP-derived values like DepthMap are created with expected fields.
 * The suite checks multiple value objects are built and grouped correctly.
 * This ensures the factory produces consistent inputs for StructuredMetadataBuilder.
 *
 * @internal
 */
#[CoversClass(ValueFactory::class)]
#[UsesClass(Author::class)]
#[UsesClass(TiffDataFactory::class)]
#[UsesClass(CaptureHardware::class)]
#[UsesClass(CaptureSettings::class)]
#[UsesClass(CreatorContact::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(Image::class)]
#[UsesClass(Iptc::class)]
#[UsesClass(IptcDocument::class)]
#[UsesClass(IptcParser::class)]
#[UsesClass(LocationTime::class)]
#[UsesClass(MediaContent::class)]
#[UsesClass(Provenance::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(TechnicalData::class)]
#[UsesClass(DepthMap::class)]
final class ValueFactoryTest extends TestCase
{
    /**
     * Parses an XMP depth map packet and assembles structured metadata.
     * Verifies the DepthMap value object is created with expected fields.
     *
     * @return void
     */
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

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(DepthMap::class, $structured->content->depthMap);
        self::assertSame('ZGVwdGg=', $structured->content->depthMap->data);
        self::assertSame('image/png', $structured->content->depthMap->mime);
        self::assertSame(0.25, $structured->content->depthMap->near);
        self::assertSame(10.5, $structured->content->depthMap->far);
    }

    /**
     * Supplies XMP title/description along with creator contact information fields.
     * Confirms the assembler maps these fields into image and author metadata.
     *
     * @return void
     */
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

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        self::assertSame('Sample Title', $structured->content->image->title);
        self::assertSame('Sample Description', $structured->content->image->description);
        self::assertNotNull($structured->provenance->author->contact);
        self::assertSame('jane@example.com', $structured->provenance->author->contact->email);
        self::assertSame('+49 30 555', $structured->provenance->author->contact->phone);
        self::assertSame('Main Street 1', $structured->provenance->author->contact->address);
        self::assertSame('Berlin', $structured->provenance->author->contact->city);
        self::assertSame('BE', $structured->provenance->author->contact->region);
        self::assertSame('10115', $structured->provenance->author->contact->postalCode);
        self::assertSame('DE', $structured->provenance->author->contact->country);
        self::assertSame('https://example.com', $structured->provenance->author->contact->url);
    }

    /**
     * Wraps an IPTC IIM dataset in a Photoshop resource block.
     * Ensures the assembled metadata exposes the parsed IPTC dataset value.
     *
     * @return void
     */
    #[Test]
    public function exposesParsedIptcDatasets(): void
    {
        $iimData = $this->iimDataset(2, 5, 'Object Name');
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            iptcBlobs: [$payload],
            iptcParser: new IptcParser(),
        );

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        self::assertSame('Object Name', $structured->provenance->iptc->document?->first(2, 5));
    }

    /**
     * Uses an injected ICC parser to populate color profile values.
     *
     * @return void
     */
    #[Test]
    public function usesInjectedIccParserDependency(): void
    {
        $called = false;

        $iccParser = new readonly class(function () use (&$called): void {
            $called = true;
        }) implements IccParserInterface {
            public function __construct(
                /** @var Closure():void $onDecode */
                private Closure $onDecode,
            ) {
            }

            /**
             * @return array{
             *     description: string|null,
             *     copyright: string|null,
             *     whitePoint: array{x: float, y: float, z: float}|null,
             *     version: string|null,
             *     pcs: string|null,
             *     renderingIntent: string|null,
             *     profileId: string|null,
             *     cmmType: string|null,
             *     profileClass: string|null,
             *     colorSpace: string|null,
             *     profileDateTime: string|null,
             *     profileDateTimeUtc: string|null,
             *     profileSignature: string|null,
             *     profileFlags: string|null,
             *     primaryPlatform: string|null,
             *     deviceManufacturer: string|null,
             *     deviceModel: string|null,
             *     deviceAttributes: string|null,
             *     profileCreator: string|null,
             *     illuminant: array{x: float, y: float, z: float}|null,
             * }
             */
            public function decode(?string $profileData, array $segments = []): array
            {
                ($this->onDecode)();

                return [
                    'description'        => 'Injected ICC',
                    'copyright'          => null,
                    'whitePoint'         => null,
                    'version'            => '4.4',
                    'pcs'                => null,
                    'renderingIntent'    => null,
                    'profileId'          => null,
                    'cmmType'            => null,
                    'profileClass'       => null,
                    'colorSpace'         => null,
                    'profileDateTime'    => null,
                    'profileDateTimeUtc' => null,
                    'profileSignature'   => null,
                    'profileFlags'       => null,
                    'primaryPlatform'    => null,
                    'deviceManufacturer' => null,
                    'deviceModel'        => null,
                    'deviceAttributes'   => null,
                    'profileCreator'     => null,
                    'illuminant'         => null,
                ];
            }
        };

        $factory  = new ValueFactory(iccParser: $iccParser);
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            iccProfile: 'mock-profile',
        );

        $components = $factory->createComponents($metadata);

        self::assertTrue($called);
        self::assertSame('Injected ICC', $components['colorProfile']->profileName);
        self::assertSame('4.4', $components['colorProfile']->profileVersion);
    }

    private const string PHOTOSHOP_SIGNATURE = "Photoshop 3.0\0";

    private function resourceBlock(int $resourceId, string $data, string $name = ''): string
    {
        $nameLength = strlen($name);
        $nameField  = chr($nameLength) . $name;
        if ((strlen($nameField) % 2) !== 0) {
            $nameField .= "\0";
        }

        $block = '8BIM'
            . pack('n', $resourceId)
            . $nameField
            . pack('N', strlen($data))
            . $data;

        if ((strlen($data) % 2) !== 0) {
            $block .= "\0";
        }

        return $block;
    }

    private function iimDataset(int $record, int $dataset, string $value): string
    {
        return "\x1C" . chr($record) . chr($dataset) . pack('n', strlen($value)) . $value;
    }
}
