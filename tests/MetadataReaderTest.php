<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use Closure;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Factory\TiffDataFactory;
use MagicSunday\ImageMeta\Exif\Factory\ValueFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Factory\StructuredMetadataCache;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\CanonDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MakerNotes\SonyDecoder;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParserInterface;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserInterface;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemLocationResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemPayloadResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeKeyResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeMetadataDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeValueDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\TrackMediaParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\VideoSampleEntryParser;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParser;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactoryInterface;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserInterface;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParserInterface;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParserInterface;
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
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;
use MagicSunday\ImageMeta\Value\File as FileValue;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\GpsDestination;
use MagicSunday\ImageMeta\Value\GpsMeasurement;
use MagicSunday\ImageMeta\Value\GpsMovement;
use MagicSunday\ImageMeta\Value\GpsPosition;
use MagicSunday\ImageMeta\Value\GpsTiming;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
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
use RuntimeException;

use function chr;
use function count;
use function file_put_contents;
use function ltrim;
use function md5;
use function pack;
use function rename;
use function sha1;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises MetadataReader as the integration point across parsers and factories.
 * It assembles EXIF, XMP, and maker notes from synthetic inputs and validates the resulting model graph.
 * The coverage verifies builder and cache interactions to ensure structured metadata remains consistent.
 * These scenarios confirm consumers receive stable, readonly value objects even when sections are absent.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesMerger::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(AudioSampleEntryParser::class)]
#[UsesClass(Author::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Camera::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(CanonDecoder::class)]
#[UsesClass(Capture::class)]
#[UsesClass(CaptureHardware::class)]
#[UsesClass(CaptureSettings::class)]
#[UsesClass(ColorProfile::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(CreatorContact::class)]
#[UsesClass(Derived::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(Device::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(ExposureAdjustments::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(FileValue::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(FormatDetector::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(GpsDestination::class)]
#[UsesClass(GpsMeasurement::class)]
#[UsesClass(GpsMovement::class)]
#[UsesClass(GpsPosition::class)]
#[UsesClass(GpsTiming::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IlocBoxParser::class)]
#[UsesClass(Image::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(IsoBmffDataReference::class)]
#[UsesClass(IsoBmffDataReferenceMap::class)]
#[UsesClass(IsoBmffItemReference::class)]
#[UsesClass(IsoBmffItemReferenceMap::class)]
#[UsesClass(IsoBmffParser::class)]
#[UsesClass(IsoBmffUnresolvedItem::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesClass(ItemPayloadResolver::class)]
#[UsesClass(JpegParser::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(LocationTime::class)]
#[UsesClass(MediaContent::class)]
#[UsesClass(Lens::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(NikonDecoder::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(Provenance::class)]
#[UsesClass(QuickTimeKeyResolver::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(QuickTimeMetadataDecoder::class)]
#[UsesClass(QuickTimeValueDecoder::class)]
#[UsesClass(RegionCollection::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(SonyDecoder::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(StructuredMetadataCache::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TechnicalData::class)]
#[UsesClass(TiffDataFactory::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(TiffColorRef::class)]
#[UsesClass(TiffLayout::class)]
#[UsesClass(TiffStructure::class)]
#[UsesClass(TiffExifParser::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
#[UsesClass(TrackMediaParser::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(UserCommentExifReader::class)]
#[UsesClass(UserComment::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(Video::class)]
#[UsesClass(VideoSampleEntryParser::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(XmpParseState::class)]
#[UsesClass(XmpParser::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class MetadataReaderTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const int MARKER_APP1 = 0xE1;

    /**
     * Builds a JPEG containing EXIF and XMP APP1 segments plus a Nikon maker note.
     * Verifies MetadataReader populates blobs, documents, maker notes, and structured values.
     *
     * @return void
     */
    #[Test]
    public function readJpegPopulatesMetadata(): void
    {
        $makerNote = 'synthetic-nikon-maker-note';
        $tiff      = $this->littleEndianTiffWithMakerNote('Nikon Corporation', 'Z 9', $makerNote);
        $xmp       = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:title="Synthetic" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';
        $sofPayload = $this->buildBaselineStartOfFramePayload(8, 256, 256);

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . $this->segment(0xDB, "\x00")
            . $this->segment(0xC4, "\x00")
            . $this->segment(0xC0, $sofPayload)
            . $this->segment(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00")
            . 'scan-data'
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = MetadataReader::createDefault()->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertNull($metadata->quickTime);
        self::assertInstanceOf(ParsedExif::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
        self::assertInstanceOf(MakerNotesRecord::class, $metadata->makerNotes);
        self::assertSame('Nikon', $metadata->makerNotes->vendor);
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length);
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1);
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
        self::assertSame([], $metadata->flashPixStreams);
        self::assertSame('image/jpeg', $metadata->mimeType);
        self::assertSame(strlen($jpeg), $metadata->fileSize);
        self::assertSame('jpg', $metadata->extension);
        self::assertNull($metadata->digestSha1);
        self::assertNull($metadata->digestMd5);

        $structured = $metadata->structured();

        /** @var array{file: callable(): FileValue, container: callable(): Container, camera: callable(): Camera, lens: callable(): Lens, derived: callable(): Derived, exposure: callable(): Exposure, thumbnail: callable(): Thumbnail, rights: callable(): Rights} $componentAccessors */
        $componentAccessors = [
            'file'      => static fn (): FileValue => $structured->provenance->file,
            'container' => static fn (): Container => $structured->provenance->container,
            'camera'    => static fn (): Camera => $structured->hardware->camera,
            'lens'      => static fn (): Lens => $structured->hardware->lens,
            'derived'   => static fn (): Derived => $structured->technical->derived,
            'exposure'  => static fn (): Exposure => $structured->settings->exposure,
            'thumbnail' => static fn (): Thumbnail => $structured->content->thumbnail,
            'rights'    => static fn (): Rights => $structured->provenance->rights,
        ];

        $expectedClasses = [
            'file'      => FileValue::class,
            'container' => Container::class,
            'camera'    => Camera::class,
            'lens'      => Lens::class,
            'derived'   => Derived::class,
            'exposure'  => Exposure::class,
            'thumbnail' => Thumbnail::class,
            'rights'    => Rights::class,
        ];

        foreach ($componentAccessors as $name => $accessor) {
            $value = $accessor();
            /** @phpstan-ignore staticMethod.alreadyNarrowedType */
            self::assertInstanceOf($expectedClasses[$name], $value);
        }

        self::assertSame('image/jpeg', $structured->provenance->file->mimeType);
        self::assertSame(strlen($jpeg), $structured->provenance->file->fileSize);
        self::assertSame('jpg', $structured->provenance->file->extension);
        self::assertNull($structured->provenance->file->digestSha1);
        self::assertNull($structured->provenance->file->digestMd5);
    }

    /**
     * Uses injected parser dependencies when reading JPEG metadata.
     *
     * @return void
     */
    #[Test]
    public function readUsesInjectedParserDependencies(): void
    {
        $xmpPacket   = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" />';
        $xmpPayloads = [];

        $jpegParserFactory = new readonly class($xmpPacket) implements JpegParserFactoryInterface {
            public function __construct(private string $xmpPacket)
            {
            }

            public function create(Stream $stream): JpegParserInterface
            {
                return new readonly class($this->xmpPacket) implements JpegParserInterface {
                    public function __construct(private string $xmpPacket)
                    {
                    }

                    /**
                     * @return array{}
                     */
                    public function extractExifBlobs(): array
                    {
                        return [];
                    }

                    /**
                     * @return list<string>
                     */
                    public function extractXmpPackets(): array
                    {
                        return [$this->xmpPacket];
                    }

                    public function getIccProfile(): ?string
                    {
                        return null;
                    }

                    /**
                     * @return array{}
                     */
                    public function getIccSegments(): array
                    {
                        return [];
                    }

                    /**
                     * @return array{}
                     */
                    public function getIptcPayloads(): array
                    {
                        return [];
                    }

                    /**
                     * @return array{}
                     */
                    public function getFlashPixStreams(): array
                    {
                        return [];
                    }

                    /**
                     * @return array{}
                     */
                    public function getAudioStreams(): array
                    {
                        return [];
                    }

                    public function getMpfDocument(): ?MpfDocument
                    {
                        return null;
                    }

                    public function getFrameSamplePrecision(): ?int
                    {
                        return null;
                    }

                    public function getFrameHeight(): ?int
                    {
                        return null;
                    }

                    public function getFrameWidth(): ?int
                    {
                        return null;
                    }

                    public function getFrameComponentSamplingFactors(): ?array
                    {
                        return null;
                    }

                    public function getFrameYCbCrSubSampling(): ?array
                    {
                        return null;
                    }
                };
            }
        };

        $xmpParser = new readonly class(function (string $xml) use (&$xmpPayloads): void {
            $xmpPayloads[] = $xml;
        }) implements XmpParserInterface {
            public function __construct(
                /** @var Closure(string):void $onParse */
                private Closure $onParse,
            ) {
            }

            public function parse(string $xml): XmpDocument
            {
                ($this->onParse)($xml);

                return new XmpDocument([]);
            }
        };

        $tiffParser = new class implements TiffExifParserInterface {
            public function parseFromBlob(
                string $tiffBlob,
                ?Registry $registry = null,
                bool $jpegContext = false,
                bool $embeddedContext = false,
            ): ParsedExif {
                throw new RuntimeException('EXIF parser must not be called');
            }
        };

        $iptcParser = new class implements IptcParserInterface {
            public function parse(string $payload): IptcDocument
            {
                return new IptcDocument([]);
            }
        };

        $isoFactory = new class implements IsoBmffParserFactoryInterface {
            public function create(Stream $stream): IsoBmffParserInterface
            {
                throw new RuntimeException('ISO BMFF parser factory must not be called');
            }
        };

        $path = $this->writeTempFile("\xFF\xD8\xFF\xD9", 'jpg');

        try {
            $metadata = new MetadataReader(
                tiffReader: $tiffParser,
                appleMerger: new AppleMakerNotesMerger(),
                xmpParser: $xmpParser,
                iptcParser: $iptcParser,
                formatDetector: new FormatDetector(),
                jpegParserFactory: $jpegParserFactory,
                isoBmffParserFactory: $isoFactory,
            );
            $result = $metadata->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame([$xmpPacket], $result->xmpBlobs);
        self::assertSame([$xmpPacket], $xmpPayloads);
    }

    /**
     * Reads a JPEG while requesting digest computation.
     * Ensures both SHA-1 and MD5 checksums are calculated and propagated to structured file metadata.
     *
     * @return void
     */
    #[Test]
    public function readJpegWithDigestsPopulatesChecksums(): void
    {
        $makerNote  = 'digest-maker-note';
        $tiff       = $this->littleEndianTiffWithMakerNote('Canon', 'EOS R6', $makerNote);
        $sofPayload = $this->buildBaselineStartOfFramePayload(8, 256, 256);

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . $this->segment(0xDB, "\x00")
            . $this->segment(0xC4, "\x00")
            . $this->segment(0xC0, $sofPayload)
            . $this->segment(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00")
            . 'scan-data'
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpeg');

        try {
            $metadata = MetadataReader::createDefault()->read($path, true);
        } finally {
            @unlink($path);
        }

        $expectedSha1 = sha1($jpeg);
        $expectedMd5  = md5($jpeg);

        self::assertSame($expectedSha1, $metadata->digestSha1);
        self::assertSame($expectedMd5, $metadata->digestMd5);

        $structured = $metadata->structured();
        self::assertSame($expectedSha1, $structured->provenance->file->digestSha1);
        self::assertSame($expectedMd5, $structured->provenance->file->digestMd5);
    }

    /**
     * Creates a JPEG with a baseline SOF but no EXIF BitsPerSample tag.
     * Confirms the structured image falls back to SOF precision for bits per sample and dimensions.
     *
     * @return void
     */
    #[Test]
    public function structuredImageBitsPerSampleFallbacksToFramePrecision(): void
    {
        $sofPayload = $this->buildBaselineStartOfFramePayload(8, 672, 448);

        $jpeg = "\xFF\xD8"
            . $this->segment(0xC0, $sofPayload)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = MetadataReader::createDefault()->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(8, $metadata->jpegBitsPerSample);

        $image = $metadata->structured()->content->image;

        self::assertSame(8, $image->bitsPerSample);
        self::assertSame(448, $image->width);
        self::assertSame(672, $image->height);
    }

    /**
     * Builds an ISO BMFF payload with Exif/XMP boxes and QuickTime metadata.
     * Verifies the reader extracts blobs, maker notes, QuickTime identifiers, and item references.
     *
     * @return void
     */
    #[Test]
    public function readIsoBmffPopulatesMetadata(): void
    {
        $makerNote = 'synthetic-sony-maker-note';
        $tiff      = $this->littleEndianTiffWithMakerNote('Sony Corporation', 'ILCE-1', $makerNote, true);
        $xmp       = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="Agent" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';
        $identifier = 'qt-meta-identifier';

        $ftyp = $this->box('ftyp', 'isom' . pack('N', 0));
        // SingleItemTypeReferenceBox is a plain Box, not a FullBox
        $irefEntry  = $this->box('cdsc', pack('n', 2) . pack('n', 1) . pack('n', 3));
        $iref       = $this->fullBox('iref', $irefEntry);
        $meta       = $this->fullBox('meta', $this->box('Exif', pack('N', 0) . $tiff) . $this->box('XMP ', $xmp) . $iref);
        $moov       = $this->quickTimeMoov($identifier);
        $isoPayload = $ftyp . $meta . $moov;

        $path = $this->writeTempFile($isoPayload);

        try {
            $metadata = MetadataReader::createDefault()->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertInstanceOf(QuickTimeMeta::class, $metadata->quickTime);
        self::assertSame($identifier, $metadata->quickTime->contentIdentifier());
        self::assertInstanceOf(IsoBmffItemReferenceMap::class, $metadata->isoBmffItemReferences);
        $itemReferences = $metadata->isoBmffItemReferences->referencesFor(2);
        self::assertCount(1, $itemReferences);
        self::assertSame('cdsc', $itemReferences[0]->relation);
        self::assertSame(3, $itemReferences[0]->toItemId);
        self::assertNull($metadata->isoBmffDataReferences);
        self::assertSame([], $metadata->isoBmffUnresolvedItems);
        self::assertInstanceOf(ParsedExif::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
        self::assertInstanceOf(MakerNotesRecord::class, $metadata->makerNotes);
        self::assertSame('Sony', $metadata->makerNotes->vendor);
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length);
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1);
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
    }

    /**
     * Inserts duplicate XMP packets into a JPEG APP1 sequence.
     * Ensures MetadataReader de-duplicates XMP blobs based on content hash.
     *
     * @return void
     */
    #[Test]
    public function deduplicatesXmpPacketsByHash(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" /></x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg);

        try {
            $metadata = MetadataReader::createDefault()->read($path);
        } finally {
            @unlink($path);
        }

        self::assertCount(1, $metadata->xmpBlobs);
        self::assertSame($xmp, $metadata->xmpBlobs[0]);
    }

    /**
     * Writes the provided binary payload to a temporary file and returns its path.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param string $payload Binary payload to persist on disk.
     *
     * @return string Absolute path to the temporary file containing the payload.
     */
    private function writeTempFile(string $payload, ?string $extension = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        if ($path === false) {
            self::fail('Unable to allocate temporary file');
        }

        file_put_contents($path, $payload);

        if ($extension !== null) {
            $suffix = ltrim($extension, '.');
            $target = $path . '.' . $suffix;
            if (!rename($path, $target)) {
                @unlink($path);
                self::fail('Unable to rename temporary file');
            }

            $path = $target;
        }

        return $path;
    }

    /**
     * Builds a minimal little-endian TIFF containing make/model strings and maker notes.
     */
    private function littleEndianTiffWithMakerNote(string $make, string $model, string $makerNote, bool $includeImageDimensions = false): string
    {
        $makeData  = $make . "\0";
        $modelData = $model . "\0";

        // Pad values to even length for TIFF 6.0 word-alignment
        $makePad  = strlen($makeData) % 2 !== 0 ? "\0" : '';
        $modelPad = strlen($modelData) % 2 !== 0 ? "\0" : '';
        $notePad  = strlen($makerNote) % 2 !== 0 ? "\0" : '';

        $ifd0Offset = 8;
        $ifd0Count  = $includeImageDimensions ? 5 : 3;
        $ifd0Size   = 2 + ($ifd0Count * 12) + 4;

        $currentOffset = $ifd0Offset + $ifd0Size;

        $makeOffset = $currentOffset;
        $currentOffset += strlen($makeData) + strlen($makePad);

        $modelOffset = $currentOffset;
        $currentOffset += strlen($modelData) + strlen($modelPad);

        $exifIfdOffset = $currentOffset;
        $exifIfdSize   = 2 + 12 + 4;

        $makerNoteOffset = $exifIfdOffset + $exifIfdSize;

        $dimensionEntries = $includeImageDimensions
            ? pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', 3)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0)
                . pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', 3)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0)
            : '';

        $ifd0 = pack('v', $ifd0Count)
            . $dimensionEntries
            . pack('v', ExifTag::MAKE)
            . pack('v', 2)
            . pack('V', strlen($makeData))
            . pack('V', $makeOffset)
            . pack('v', ExifTag::MODEL)
            . pack('v', 2)
            . pack('V', strlen($modelData))
            . pack('V', $modelOffset)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', 4)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', 1)
            . pack('v', ExifTag::MAKER_NOTE)
            . pack('v', 7)
            . pack('V', strlen($makerNote))
            . pack('V', $makerNoteOffset)
            . pack('V', 0);

        return 'II'
            . pack('v', 0x2A)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $makeData . $makePad
            . $modelData . $modelPad
            . $exifIfd
            . $makerNote . $notePad;
    }

    /**
     * Builds a baseline start of frame payload with three colour components.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int $precision Sample precision reported by the SOF marker.
     * @param int $height    Frame height in image lines.
     * @param int $width     Frame width in samples per line.
     *
     * @return string Serialized SOF payload excluding marker and length fields.
     */
    private function buildBaselineStartOfFramePayload(int $precision, int $height, int $width): string
    {
        $components = [
            [1, 0x22, 0],
            [2, 0x11, 1],
            [3, 0x11, 1],
        ];

        $payload = pack('CnnC', $precision, $height, $width, count($components));

        foreach ($components as [$id, $sampling, $table]) {
            $payload .= pack('CCC', $id, $sampling, $table);
        }

        return $payload;
    }

    /**
     * Wraps a payload with a JPEG marker and its big-endian length field.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param int    $marker  Marker identifier without the 0xFF prefix.
     * @param string $payload Binary segment payload.
     *
     * @return string Serialized JPEG segment.
     */
    private function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Constructs a standard ISO BMFF box header around the provided payload.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Box payload data.
     *
     * @return string Serialized box bytes.
     */
    private function box(string $type, string $payload): string
    {
        $size = 8 + strlen($payload);

        return pack('N', $size) . $type . $payload;
    }

    /**
     * Constructs a full box (including version and flags) around a payload.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Box payload data.
     * @param int    $version Version byte to prepend to the payload.
     * @param int    $flags   Three-byte flag field to prepend to the payload.
     *
     * @return string Serialized full box bytes.
     */
    private function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version)
            . chr(($flags >> 16) & 0xFF)
            . chr(($flags >> 8) & 0xFF)
            . chr($flags & 0xFF);

        return $this->box($type, $header . $payload);
    }

    /**
     * Builds a QuickTime moov/udta/meta structure containing a content identifier.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param string $value Content identifier to store inside the structure.
     *
     * @return string Serialized QuickTime `moov` box structure.
     */
    private function quickTimeMoov(string $value): string
    {
        $keysEntry = pack('N', 9 + strlen('com.apple.quicktime.content.identifier'))
            . 'mdta'
            . 'com.apple.quicktime.content.identifier'
            . "\0";
        $keys = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keysEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . $value);
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $hdlr        = $this->box('hdlr', "\0\0\0\0\0\0\0\0mdta" . str_repeat("\0", 12));
        $metaPayload = "\0\0\0\0" . $hdlr . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $udta        = $this->box('udta', $meta);

        $mvhd = $this->fullBox('mvhd', pack('NNN', 0, 0, 1) . str_repeat("\0", 80) . pack('N', 1));
        $trak = $this->minimalTrak();

        return $this->box('moov', $mvhd . $trak . $udta);
    }

    private function minimalTrak(): string
    {
        $tkhd = $this->fullBox('tkhd', pack('NNNx4N', 0, 0, 1, 0) . str_repeat("\0", 60));
        $hdlr = $this->fullBox('hdlr', "\0\0\0\0vide" . str_repeat("\0", 12) . "\0");
        $mdhd = $this->fullBox('mdhd', pack('NNN', 0, 0, 1) . str_repeat("\0", 8));
        $url  = $this->fullBox('url ', '', 0, 1);
        $dref = $this->fullBox('dref', pack('N', 1) . $url);
        $dinf = $this->box('dinf', $dref);
        $vmhd = $this->fullBox('vmhd', str_repeat("\0", 8), 0, 1);
        $stsd = $this->fullBox('stsd', pack('N', 1) . $this->videoSampleEntry('avc1', 1, 1));
        $stts = $this->fullBox('stts', pack('N', 0));
        $stsc = $this->fullBox('stsc', pack('N', 0));
        $stsz = $this->fullBox('stsz', pack('NN', 0, 0));
        $stco = $this->fullBox('stco', pack('N', 0));
        $stbl = $this->box('stbl', $stsd . $stts . $stsc . $stsz . $stco);
        $minf = $this->box('minf', $vmhd . $dinf . $stbl);
        $mdia = $this->box('mdia', $hdlr . $mdhd . $minf);

        return $this->box('trak', $tkhd . $mdia);
    }

    private function videoSampleEntry(string $format, int $width, int $height): string
    {
        $compressor = str_pad('', 31, "\0");

        $payload = str_repeat("\0", 6)
            . pack('n', 1)
            . str_repeat("\0", 16)
            . pack('n', $width)
            . pack('n', $height)
            . pack('N', 0x00480000)
            . pack('N', 0x00480000)
            . pack('N', 0)
            . pack('n', 1)
            . "\0"
            . $compressor
            . pack('n', 24)
            . pack('n', 0xFFFF);

        return $this->box($format, $payload);
    }
}
