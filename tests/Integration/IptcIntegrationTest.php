<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Integration;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\TiffDataFactory;
use MagicSunday\ImageMeta\Factory\Structured\ValueFactory;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MakerNotes\SimpleDecoder;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Jpeg\FlashPixHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\FlashPixStreamAssembler;
use MagicSunday\ImageMeta\Parse\Jpeg\IccProfileAssembler;
use MagicSunday\ImageMeta\Parse\Jpeg\IccProfileHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\IptcSegmentHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegApp1Handler;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegAudioSegmentParser;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegFrameValidator;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegMarkerScanner;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParser;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserConfig;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserFactory;
use MagicSunday\ImageMeta\Parse\Jpeg\JumbfTransportParser;
use MagicSunday\ImageMeta\Parse\Jpeg\MarkerHandlerRegistry;
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

use function chr;
use function file_put_contents;
use function implode;
use function ltrim;
use function pack;
use function rename;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises MetadataReader as the integration point for IPTC extraction from JPEG APP13.
 * Builds a synthetic JPEG with a Photoshop 8BIM APP13 segment containing IPTC IIM datasets
 * and verifies the data flows through into structured output.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesMerger::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(Author::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Camera::class)]
#[UsesClass(Capture::class)]
#[UsesClass(CaptureHardware::class)]
#[UsesClass(CaptureSettings::class)]
#[UsesClass(ColorProfile::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(CreatorContact::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Device::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(ExposureAdjustments::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(FileValue::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(FlashPixHandler::class)]
#[UsesClass(FlashPixStreamAssembler::class)]
#[UsesClass(Focus::class)]
#[UsesClass(FormatDetector::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(GpsDestination::class)]
#[UsesClass(GpsMeasurement::class)]
#[UsesClass(GpsMovement::class)]
#[UsesClass(GpsPosition::class)]
#[UsesClass(GpsTiming::class)]
#[UsesClass(IccProfileAssembler::class)]
#[UsesClass(IccProfileHandler::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Iptc::class)]
#[UsesClass(IptcDocument::class)]
#[UsesClass(IptcParser::class)]
#[UsesClass(IptcSegmentHandler::class)]
#[UsesClass(JpegApp1Handler::class)]
#[UsesClass(JpegAudioSegmentParser::class)]
#[UsesClass(JpegFrameValidator::class)]
#[UsesClass(JpegMarkerScanner::class)]
#[UsesClass(JpegParser::class)]
#[UsesClass(JpegParserConfig::class)]
#[UsesClass(JpegParserFactory::class)]
#[UsesClass(JumbfTransportParser::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(LocationTime::class)]
#[UsesClass(MarkerHandlerRegistry::class)]
#[UsesClass(MediaContent::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(Provenance::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(RegionCollection::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(SimpleDecoder::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(TechnicalData::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(TiffColorRef::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(TiffDataFactory::class)]
#[UsesClass(TiffLayout::class)]
#[UsesClass(TiffStructure::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(UserComment::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[UsesClass(XmpParser::class)]
#[UsesClass(XmpParseState::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class IptcIntegrationTest extends TestCase
{
    private const int MARKER_APP13 = 0xED;

    private function buildJpeg(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
    }

    private function readMetadataFromJpeg(string $jpeg): Metadata
    {
        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            return MetadataReader::createDefault()->read($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Builds a JPEG with an APP13 segment containing Photoshop 8BIM IPTC datasets
     * (Caption, By-line, Keywords) and verifies that MetadataReader populates
     * iptcBlobs, iptcDoc, and structured IPTC metadata.
     */
    #[Test]
    public function readJpegWithApp13PopulatesIptcMetadata(): void
    {
        $caption  = 'A synthetic test caption';
        $byLine   = 'Test Author';
        $keyword1 = 'landscape';
        $keyword2 = 'sunset';

        $iptcData = $this->buildIptcIimData([
            [2, 120, $caption],
            [2, 80, $byLine],
            [2, 25, $keyword1],
            [2, 25, $keyword2],
        ]);

        $app13Payload = $this->buildPhotoshopApp13Payload($iptcData);

        $metadata = $this->readMetadataFromJpeg(
            $this->buildJpeg(
                $this->segment(self::MARKER_APP13, $app13Payload),
            ),
        );

        self::assertCount(1, $metadata->iptcBlobs);
        self::assertSame($app13Payload, $metadata->iptcBlobs[0]);
        self::assertInstanceOf(IptcDocument::class, $metadata->iptcDoc);

        self::assertSame($caption, $metadata->iptcDoc->first(2, 120));
        self::assertSame($byLine, $metadata->iptcDoc->first(2, 80));
        self::assertSame([$keyword1, $keyword2], $metadata->iptcDoc->values(2, 25));

        $structured = $metadata->structured();
        self::assertInstanceOf(IptcDocument::class, $structured->provenance->iptc->document);
        self::assertSame($caption, $structured->provenance->iptc->document->first(2, 120));
        self::assertSame($byLine, $structured->provenance->iptc->document->first(2, 80));
        self::assertSame([$keyword1, $keyword2], $structured->provenance->iptc->document->values(2, 25));
    }

    /**
     * Verifies that multiple APP13 segments in a JPEG are all captured and merged.
     */
    #[Test]
    public function readJpegWithMultipleApp13SegmentsMergesIptcData(): void
    {
        $caption    = 'Caption from first segment';
        $objectName = 'Object from second segment';

        $iptcData1 = $this->buildIptcIimData([
            [2, 120, $caption],
        ]);

        $iptcData2 = $this->buildIptcIimData([
            [2, 5, $objectName],
        ]);

        $app13Payload1 = $this->buildPhotoshopApp13Payload($iptcData1);
        $app13Payload2 = $this->buildPhotoshopApp13Payload($iptcData2);

        $metadata = $this->readMetadataFromJpeg(
            $this->buildJpeg(
                $this->segment(self::MARKER_APP13, $app13Payload1),
                $this->segment(self::MARKER_APP13, $app13Payload2),
            ),
        );

        self::assertCount(2, $metadata->iptcBlobs);
        self::assertInstanceOf(IptcDocument::class, $metadata->iptcDoc);

        self::assertSame($caption, $metadata->iptcDoc->first(2, 120));
        self::assertSame($objectName, $metadata->iptcDoc->first(2, 5));
    }

    /**
     * Verifies that a JPEG without APP13 leaves IPTC properties at their defaults.
     */
    #[Test]
    public function readJpegWithoutApp13LeavesIptcEmpty(): void
    {
        $metadata = $this->readMetadataFromJpeg($this->buildJpeg());

        self::assertSame([], $metadata->iptcBlobs);
        self::assertNull($metadata->iptcDoc);
        self::assertNull($metadata->structured()->provenance->iptc->document);
    }

    /**
     * Builds a sequence of IPTC IIM dataset records.
     *
     * Each entry is [record, dataset, value]. The record marker byte (0x1C)
     * precedes each dataset per IPTC IIM specification.
     *
     * @param list<array{int, int, string}> $datasets List of [record, dataset, value] tuples.
     *
     * @return string Serialized IPTC IIM data.
     */
    private function buildIptcIimData(array $datasets): string
    {
        $data = '';

        foreach ($datasets as [$record, $dataset, $value]) {
            $data .= "\x1C"
                . chr($record)
                . chr($dataset)
                . pack('n', strlen($value))
                . $value;
        }

        return $data;
    }

    /**
     * Wraps IPTC IIM data inside a Photoshop APP13 resource block.
     *
     * The payload includes the "Photoshop 3.0\0" signature followed by
     * an 8BIM resource block with resource ID 0x0404 (IPTC-NAA).
     *
     * @param string $iptcData Serialized IPTC IIM data.
     *
     * @return string Complete APP13 payload with Photoshop envelope.
     */
    private function buildPhotoshopApp13Payload(string $iptcData): string
    {
        $signature = "Photoshop 3.0\0";

        // 8BIM resource block: signature(4) + resourceId(2) + nameLength(1) + padding(1) + dataSize(4) + data
        $resource = '8BIM'
            . pack('n', 0x0404)
            . "\x00"
            . "\x00"
            . pack('N', strlen($iptcData))
            . $iptcData;

        // Pad resource data to even length if needed
        if ((strlen($iptcData) % 2) !== 0) {
            $resource .= "\x00";
        }

        return $signature . $resource;
    }

    /**
     * Wraps a payload with a JPEG marker and its big-endian length field.
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
     * Writes the provided binary payload to a temporary file and returns its path.
     *
     * @param string      $payload   Binary payload to persist on disk.
     * @param string|null $extension Optional file extension to append.
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
}
