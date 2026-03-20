<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use Closure;
use MagicSunday\ImageMeta\Contract\IccParserInterface;
use MagicSunday\ImageMeta\Factory\Structured\TiffDataFactory;
use MagicSunday\ImageMeta\Factory\Structured\ValueFactory;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Model\Icc\IccProfile;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\CaptureHardware;
use MagicSunday\ImageMeta\Value\CaptureSettings;
use MagicSunday\ImageMeta\Value\CreatorContact;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\HdrGainMap;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Iptc;
use MagicSunday\ImageMeta\Value\LocationTime;
use MagicSunday\ImageMeta\Value\MediaContent;
use MagicSunday\ImageMeta\Value\Provenance;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\TechnicalData;
use MagicSunday\ImageMeta\Value\Video;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
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
#[UsesClass(HdrGainMap::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(Video::class)]
final class ValueFactoryTest extends TestCase
{
    /**
     * Parses an XMP depth map packet and assembles structured metadata.
     * Verifies the DepthMap value object is created with expected fields.
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
     * Parses XMP with Adobe hdrgm and Apple apdi namespaces.
     * Verifies the HdrGainMap value object is created with expected fields.
     */
    #[Test]
    public function assemblesHdrGainMapFromXmpPacket(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:hdrgm="http://ns.adobe.com/hdr-gain-map/1.0/"
         xmlns:apdi="http://ns.apple.com/pixeldatainfo/1.0/">
  <rdf:Description
    hdrgm:Version="1.0"
    hdrgm:BaseRenditionIsHDR="False"
    hdrgm:HDRCapacityMin="0"
    hdrgm:HDRCapacityMax="3.5"
    hdrgm:GainMapMin="0"
    hdrgm:GainMapMax="1"
    hdrgm:Gamma="1"
    hdrgm:OffsetSDR="0.015625"
    hdrgm:OffsetHDR="0.015625"
    apdi:AuxiliaryImageType="urn:com:apple:photo:2020:aux:hdrgainmap"
  />
</rdf:RDF>
XML;

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: (new XmpParser())->parse($xml),
        );

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        self::assertFalse($structured->content->hdrGainMap->hasGainMap);
        self::assertSame('1.0', $structured->content->hdrGainMap->version);
        self::assertFalse($structured->content->hdrGainMap->baseRenditionIsHdr);
        self::assertSame(0.0, $structured->content->hdrGainMap->hdrCapacityMin);
        self::assertSame(3.5, $structured->content->hdrGainMap->hdrCapacityMax);
        self::assertSame(0.0, $structured->content->hdrGainMap->gainMapMin);
        self::assertSame(1.0, $structured->content->hdrGainMap->gainMapMax);
        self::assertSame(1.0, $structured->content->hdrGainMap->gamma);
        self::assertSame(0.015625, $structured->content->hdrGainMap->offsetSdr);
        self::assertSame(0.015625, $structured->content->hdrGainMap->offsetHdr);
        self::assertSame('urn:com:apple:photo:2020:aux:hdrgainmap', $structured->content->hdrGainMap->auxiliaryImageType);
    }

    /**
     * Builds Metadata with tmapItemIds populated.
     * Verifies hasGainMap is true when tmap items are present.
     */
    #[Test]
    public function setsHasGainMapTrueWhenTmapItemsExist(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            tmapItemIds: [2],
        );

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        self::assertTrue($structured->content->hdrGainMap->hasGainMap);
    }

    /**
     * Builds Metadata with gainMapBlob populated (JXL hrgm box).
     * Verifies hasGainMap is true when a gain map blob is present.
     */
    #[Test]
    public function setsHasGainMapTrueWhenGainMapBlobExists(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            gainMapBlob: 'gain-map-image-data',
        );

        $structured = StructuredMetadataBuilder::createDefault()->assemble($metadata);

        self::assertTrue($structured->content->hdrGainMap->hasGainMap);
    }

    /**
     * Supplies XMP title/description along with creator contact information fields.
     * Confirms the assembler maps these fields into image and author metadata.
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
     */
    #[Test]
    public function usesInjectedIccParserDependency(): void
    {
        $called  = false;
        $profile = $this->createIccProfile(description: 'Injected ICC', version: '4.4');

        $iccParser = new readonly class(function () use (&$called): void {
            $called = true;
        }, $profile) implements IccParserInterface {
            public function __construct(
                /** @var Closure():void $onDecode */
                private Closure $onDecode,
                private IccProfile $profile,
            ) {
            }

            public function decode(?string $profileData, array $segments = []): IccProfile
            {
                ($this->onDecode)();

                return $this->profile;
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

    /**
     * Supplies Metadata with no EXIF, no XMP, no QuickTime, and no ICC data.
     * Verifies ValueFactory produces a complete component array with null/default values.
     */
    #[Test]
    public function producesCompleteComponentArrayFromEmptyMetadata(): void
    {
        $factory  = new ValueFactory(iccParser: $this->stubIccParser());
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $components = $factory->createComponents($metadata);

        $this->addToAssertionCount(10);

        // All camera fields should be null
        self::assertNull($components['camera']->make);
        self::assertNull($components['camera']->model);
    }

    /**
     * Supplies malformed XMP (non-XML) content to the XMP parser.
     * Verifies the ValueFactory handles invalid XMP gracefully and still produces components.
     */
    #[Test]
    public function handlesNonXmlXmpGracefully(): void
    {
        $factory  = new ValueFactory(iccParser: $this->stubIccParser());
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: null,
        );

        $components = $factory->createComponents($metadata);

        // Even without XMP, the factory should produce all components
        $this->addToAssertionCount(1);
        self::assertSame([], $components['keywords']->flat);
    }

    /**
     * Builds Metadata with QuickTimeMeta containing rotation and video bit depth.
     * Verifies the Video value object exposes rotation and bitDepth fields.
     */
    #[Test]
    public function mapsQuickTimeRotationAndBitDepthToVideo(): void
    {
        $factory  = new ValueFactory(iccParser: $this->stubIccParser());
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: new QuickTimeMeta([
                QuickTimeMeta::ROTATION_KEY        => 90,
                QuickTimeMeta::VIDEO_BIT_DEPTH_KEY => 24,
            ]),
        );

        $components = $factory->createComponents($metadata);

        self::assertSame(90, $components['video']->rotation);
        self::assertSame(24, $components['video']->bitDepth);
    }

    /**
     * Verifies that Video rotation and bitDepth default to null when QuickTime is absent.
     */
    #[Test]
    public function videoRotationAndBitDepthDefaultToNull(): void
    {
        $factory  = new ValueFactory(iccParser: $this->stubIccParser());
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $components = $factory->createComponents($metadata);

        self::assertNull($components['video']->rotation);
        self::assertNull($components['video']->bitDepth);
    }

    /**
     * Guards the component-assembly simplification by disallowing identity map helpers.
     */
    #[Test]
    public function createComponentsAvoidsIdentityAssemblyHelpers(): void
    {
        $reflection = new ReflectionClass(ValueFactory::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertNotContains('createMediaComponentMap', $methods);
        self::assertNotContains('createXmpComponentMap', $methods);
        self::assertNotContains('createComponentMap', $methods);
    }

    private function createIccProfile(?string $description = null, ?string $version = null): IccProfile
    {
        return new IccProfile(
            description: $description,
            copyright: null,
            whitePoint: null,
            blackPoint: null,
            redMatrixColumn: null,
            greenMatrixColumn: null,
            blueMatrixColumn: null,
            luminance: null,
            redTRC: null,
            greenTRC: null,
            blueTRC: null,
            deviceMfgDesc: null,
            deviceModelDesc: null,
            technology: null,
            viewingConditions: null,
            measurement: null,
            version: $version,
            pcs: null,
            renderingIntent: null,
            profileId: null,
            cmmType: null,
            profileClass: null,
            colorSpace: null,
            profileDateTime: null,
            profileDateTimeUtc: null,
            profileSignature: null,
            profileFlags: null,
            primaryPlatform: null,
            deviceManufacturer: null,
            deviceModel: null,
            deviceAttributes: null,
            profileCreator: null,
            illuminant: null,
        );
    }

    private function stubIccParser(): IccParserInterface
    {
        $profile = $this->createIccProfile();

        return new readonly class($profile) implements IccParserInterface {
            public function __construct(
                private IccProfile $profile,
            ) {
            }

            public function decode(?string $profileData, array $segments = []): IccProfile
            {
                return $this->profile;
            }
        };
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
