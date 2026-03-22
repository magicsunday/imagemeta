<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Integration;

use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Core\Util\StringUtil;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\PhotoCalculator;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\CameraFactory;
use MagicSunday\ImageMeta\Factory\Structured\DeviceFactory;
use MagicSunday\ImageMeta\Factory\Structured\ExposureFactory;
use MagicSunday\ImageMeta\Factory\Structured\GpsFactory;
use MagicSunday\ImageMeta\Factory\Structured\ImageFactory;
use MagicSunday\ImageMeta\Factory\Structured\LensFactory;
use MagicSunday\ImageMeta\Factory\Structured\MotionFactory;
use MagicSunday\ImageMeta\Factory\Structured\MultiPictureFactory;
use MagicSunday\ImageMeta\Factory\Structured\RegionsFactory;
use MagicSunday\ImageMeta\Factory\Structured\SceneFactory;
use MagicSunday\ImageMeta\Factory\Structured\SensorFactory;
use MagicSunday\ImageMeta\Factory\Structured\TemporalFactory;
use MagicSunday\ImageMeta\Factory\Structured\TiffDataFactory;
use MagicSunday\ImageMeta\Factory\Structured\ValueFactory;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\FlashPix\FlashPixDocument;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpValueAccumulator;
use MagicSunday\ImageMeta\Parse\FlashPix\FlashPixParser;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParseState;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\CaptureHardware;
use MagicSunday\ImageMeta\Value\CaptureSettings;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\CreatorContact;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\HdrGainMap;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Iptc;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\LocationTime;
use MagicSunday\ImageMeta\Value\MediaContent;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Provenance;
use MagicSunday\ImageMeta\Value\RegionCollection;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\TechnicalData;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffColorRef;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\TiffLayout;
use MagicSunday\ImageMeta\Value\TiffStructure;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Value\UserComment;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Exercises the Metadata aggregate as the central container for parsed blobs and documents.
 * It builds instances with EXIF, XMP, QuickTime, and maker note inputs to verify storage.
 * The suite validates structured metadata assembly via the builder/cache paths.
 * This ensures callers get consistent readonly values derived from the aggregate.
 */
#[UsesClass(ParsedExif::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IptcDocument::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(IsoBmffItemReference::class)]
#[UsesClass(IsoBmffItemReferenceMap::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(IptcParser::class)]
#[UsesClass(XmpParseState::class)]
#[UsesClass(XmpParser::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(Author::class)]
#[UsesClass(Camera::class)]
#[UsesClass(Capture::class)]
#[UsesClass(ColorProfile::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Device::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(File::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(Gps::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(RegionCollection::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[CoversClass(Metadata::class)]
#[UsesClass(DateTimeUtil::class)]
#[UsesClass(StringUtil::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(PhotoCalculator::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(CameraFactory::class)]
#[UsesClass(DeviceFactory::class)]
#[UsesClass(ExposureFactory::class)]
#[UsesClass(GpsFactory::class)]
#[UsesClass(ImageFactory::class)]
#[UsesClass(LensFactory::class)]
#[UsesClass(MotionFactory::class)]
#[UsesClass(MultiPictureFactory::class)]
#[UsesClass(RegionsFactory::class)]
#[UsesClass(SceneFactory::class)]
#[UsesClass(SensorFactory::class)]
#[UsesClass(TemporalFactory::class)]
#[UsesClass(TiffDataFactory::class)]
#[UsesClass(FlashPixDocument::class)]
#[UsesClass(XmpContainer::class)]
#[UsesClass(XmpValueAccumulator::class)]
#[UsesClass(FlashPixParser::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccParser::class)]
#[UsesClass(IccTagDecoder::class)]
#[UsesClass(CaptureHardware::class)]
#[UsesClass(CaptureSettings::class)]
#[UsesClass(CreatorContact::class)]
#[UsesClass(DepthMap::class)]
#[UsesClass(ExposureAdjustments::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(HdrGainMap::class)]
#[UsesClass(Iptc::class)]
#[UsesClass(LocationTime::class)]
#[UsesClass(MediaContent::class)]
#[UsesClass(Provenance::class)]
#[UsesClass(TechnicalData::class)]
#[UsesClass(TiffColorRef::class)]
#[UsesClass(TiffLayout::class)]
#[UsesClass(TiffStructure::class)]
#[UsesClass(UserComment::class)]
final class MetadataTest extends TestCase
{
    private function createParsedExifDocument(string $make, string $model): ParsedExif
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 2, 1, $make),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 2, 1, $model),
        ]);

        return new ParsedExif($ifd0, null, null, null, null);
    }

    /**
     * @param list<string> $exifBlobs
     * @param list<string> $xmpBlobs
     */
    private function createMetadataWithCoreDocuments(
        array $exifBlobs,
        string $contentIdentifier,
        string $make,
        string $model,
        array $xmpBlobs,
        string $xmpDate,
    ): Metadata {
        return new Metadata(
            $exifBlobs,
            new QuickTimeMeta([
                'com.apple.quicktime.content.identifier' => $contentIdentifier,
            ]),
            $this->createParsedExifDocument($make, $model),
            $xmpBlobs,
            new XmpDocument([
                '{http://ns.adobe.com/photoshop/1.0/}DateCreated' => $xmpDate,
            ]),
        );
    }

    /**
     * Stores provided metadata components and exposes them via accessors.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function storesProvidedMetadataComponents(): void
    {
        $exifBlobs = [
            'primary-exif-blob',
            'alternate-exif-blob',
        ];

        $xmpBlobs = [
            '<x:xmpmeta>\n  <!-- primary -->\n</x:xmpmeta>',
            '<x:xmpmeta>\n  <!-- secondary -->\n</x:xmpmeta>',
        ];
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.content.identifier' => 'movie-123',
        ]);
        $exifDoc = $this->createParsedExifDocument('Canon', 'EOS R5');
        $xmpDoc  = new XmpDocument([
            '{http://ns.adobe.com/photoshop/1.0/}DateCreated' => '2024-05-01',
        ]);

        $itemReferences = new IsoBmffItemReferenceMap([
            1 => [
                1 => [new IsoBmffItemReference('cdsc', 2)],
            ],
        ]);

        $iccProfile  = 'icc-profile';
        $iccSegments = ['seg-1', 'seg-2'];
        $sampling    = [
            1 => ['horizontal' => 2, 'vertical' => 2],
            2 => ['horizontal' => 1, 'vertical' => 1],
        ];

        $metadata = new Metadata(
            exifBlobs: $exifBlobs,
            quickTime: $quickTime,
            exifDoc: $exifDoc,
            xmpBlobs: $xmpBlobs,
            xmpDoc: $xmpDoc,
            makerNotes: null,
            iccProfile: $iccProfile,
            iccSegments: $iccSegments,
            flashPixStreams: [],
            mpfDocument: null,
            jpegBitsPerSample: 12,
            jpegFrameSamplingFactors: $sampling,
            jpegYCbCrSubSampling: [2, 1],
            mimeType: 'image/heic',
            fileSize: 987_654,
            extension: 'heic',
            digestSha256: 'abc123',
            jpegFrameWidth: 4096,
            jpegFrameHeight: 2730,
            isoBmffItemReferences: $itemReferences,
        );

        self::assertSame($exifBlobs, $metadata->exifBlobs);
        self::assertSame($quickTime, $metadata->quickTime);
        self::assertSame($exifDoc, $metadata->exifDoc);
        self::assertSame($xmpBlobs, $metadata->xmpBlobs);
        self::assertSame($xmpDoc, $metadata->xmpDoc);
        self::assertSame($iccProfile, $metadata->iccProfile);
        self::assertSame($iccSegments, $metadata->iccSegments);
        self::assertSame(12, $metadata->jpegBitsPerSample);
        self::assertSame($sampling, $metadata->jpegFrameSamplingFactors);
        self::assertSame([2, 1], $metadata->jpegYCbCrSubSampling);
        self::assertSame('image/heic', $metadata->mimeType);
        self::assertSame(987_654, $metadata->fileSize);
        self::assertSame('heic', $metadata->extension);
        self::assertSame('abc123', $metadata->digestSha256);
        self::assertSame(4096, $metadata->jpegFrameWidth);
        self::assertSame(2730, $metadata->jpegFrameHeight);
        self::assertSame($itemReferences, $metadata->isoBmffItemReferences);
    }

    /**
     * Defaults optional metadata fields to null or empty collections.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function appliesNullAndEmptyDefaults(): void
    {
        $metadata = new Metadata([], null);

        self::assertSame([], $metadata->exifBlobs);
        self::assertNull($metadata->quickTime);
        self::assertNull($metadata->exifDoc);
        self::assertSame([], $metadata->xmpBlobs);
        self::assertNull($metadata->xmpDoc);
        self::assertNull($metadata->isoBmffItemReferences);
        self::assertNull($metadata->jpegBitsPerSample);
        self::assertNull($metadata->jpegFrameSamplingFactors);
        self::assertNull($metadata->jpegYCbCrSubSampling);
        self::assertNull($metadata->jpegFrameWidth);
        self::assertNull($metadata->jpegFrameHeight);
        self::assertNull($metadata->mimeType);
        self::assertNull($metadata->fileSize);
        self::assertNull($metadata->extension);
        self::assertNull($metadata->digestSha256);
    }

    /**
     * Exposes aggregated metadata values across stored components.
     * It confirms optional fields are accepted without errors.
     */
    #[Test]
    public function allowsConsumingAggregatedMetadata(): void
    {
        $exifBlobs = [
            'primary-exif-blob',
            'alternate-exif-blob',
        ];

        $xmpBlobs = [
            '<x:xmpmeta>\n  <photoshop:DateCreated>2024-06-01</photoshop:DateCreated>\n</x:xmpmeta>',
        ];
        $metadata = $this->createMetadataWithCoreDocuments(
            $exifBlobs,
            'clip-42',
            'Fujifilm',
            'X-T5',
            $xmpBlobs,
            '2024-06-01',
        );

        self::assertSame('primary-exif-blob', $metadata->exifBlobs[0]);
        self::assertSame('clip-42', $metadata->quickTime?->contentIdentifier());
        self::assertNotNull($metadata->exifDoc);
        self::assertSame('Fujifilm', $metadata->exifDoc->cameraMake());
        self::assertSame('X-T5', $metadata->exifDoc->cameraModel());
        self::assertSame(
            '2024-06-01',
            $metadata->xmpDoc?->get('http://ns.adobe.com/photoshop/1.0/', 'DateCreated')
        );
    }

    /**
     * Builds a selective XMP document from available blobs when missing.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesSelectiveXmpDocumentWhenUnavailable(): void
    {
        $xmp = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/"
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:xmp="http://ns.adobe.com/xap/1.0/"
    xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:RDF>
    <rdf:Description>
      <xmp:ModifyDate>2024-04-02T01:02:03Z</xmp:ModifyDate>
      <dc:subject>
        <rdf:Bag>
          <rdf:li>One</rdf:li>
          <rdf:li>Two</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $metadata = new Metadata([], null, xmpBlobs: [$xmp], xmpParser: new XmpParser());

        $document = $metadata->selectiveXmpDocument();

        self::assertInstanceOf(XmpDocument::class, $document);
        self::assertSame('2024-04-02T01:02:03Z', $document->get('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        self::assertSame(['One', 'Two'], $document->find('subject'));
    }

    /**
     * Merges multiple XMP blobs into a single selective document.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function mergesAllXmpBlobsWhenSelectingDocument(): void
    {
        $exifBlob = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/">
      <exif:ExposureTime>0.020000</exif:ExposureTime>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $tiffBlob = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description xmlns:tiff="http://ns.adobe.com/tiff/1.0/">
      <tiff:Model>GT-I9195</tiff:Model>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $metadata = new Metadata([], null, xmpBlobs: [$exifBlob, $tiffBlob], xmpParser: new XmpParser());

        $document = $metadata->selectiveXmpDocument();

        self::assertInstanceOf(XmpDocument::class, $document);
        self::assertSame('0.020000', $document->string('http://ns.adobe.com/exif/1.0/', 'ExposureTime'));
        self::assertSame('GT-I9195', $document->string('http://ns.adobe.com/tiff/1.0/', 'Model'));
    }

    /**
     * Reuses an existing XMP document when present.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function reusesExistingXmpDocument(): void
    {
        $xmpDoc = (new XmpParser())->parse('<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"></rdf:RDF>');

        $metadata = new Metadata([], null, null, [], $xmpDoc);

        self::assertSame($xmpDoc, $metadata->selectiveXmpDocument());
    }

    /**
     * Builds a selective IPTC document from Photoshop resource blocks.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesSelectiveIptcDocumentWhenUnavailable(): void
    {
        $iimData       = "\x1C" . chr(2) . chr(5) . pack('n', 11) . 'Object Name';
        $nameField     = "\0\0";
        $resourceBlock = '8BIM'
            . pack('n', 0x0404)
            . $nameField
            . pack('N', strlen($iimData))
            . $iimData;

        if ((strlen($iimData) % 2) !== 0) {
            $resourceBlock .= "\0";
        }

        $payload = "Photoshop 3.0\0" . $resourceBlock;

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            iptcBlobs: [$payload],
            iptcParser: new IptcParser(),
        );

        $document = $metadata->selectiveIptcDocument();

        self::assertInstanceOf(IptcDocument::class, $document);
        self::assertSame('Object Name', $document->first(2, 5));
    }

    /**
     * Caches the structured metadata aggregate.
     * It ensures cached results are reused on subsequent access.
     */
    #[Test]
    public function cachesStructuredAggregate(): void
    {
        $builder  = StructuredMetadataBuilder::createDefault();
        $resolver = static fn (Metadata $m): StructuredMetadata => $builder->assemble($m);
        $metadata = new Metadata([], null, structuredResolver: $resolver);

        $first  = $metadata->structured();
        $second = $metadata->structured();

        self::assertSame($first, $second);
    }
}
