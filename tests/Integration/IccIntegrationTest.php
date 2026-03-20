<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Integration;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\PayloadGuard;
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
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Icc\IccBinaryReader;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
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
use function str_pad;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises MetadataReader as the integration point for ICC profile extraction from JPEG APP2.
 * Builds a synthetic JPEG with APP2 ICC segments and verifies the data flows through
 * into StructuredMetadata.colorProfile.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesMerger::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(Author::class)]
#[UsesClass(BitMask::class)]
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
#[UsesClass(IccBinaryReader::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccParser::class)]
#[UsesClass(IccProfileAssembler::class)]
#[UsesClass(IccProfileHandler::class)]
#[UsesClass(IccTagDecoder::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Iptc::class)]
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
#[UsesClass(PayloadGuard::class)]
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
final class IccIntegrationTest extends TestCase
{
    private const int MARKER_APP2 = 0xE2;

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
     * Builds a JPEG with a single-segment ICC profile inside APP2 and verifies that
     * MetadataReader populates iccProfile, iccSegments, and structured ColorProfile.
     */
    #[Test]
    public function readJpegWithSingleSegmentIccPopulatesColorProfile(): void
    {
        $description = 'sRGB IEC61966-2.1';
        $iccProfile  = $this->buildIccProfile($description);
        $iccSegment  = $this->buildIccSegment($iccProfile, sequenceNumber: 1, sequenceCount: 1);

        $metadata = $this->readMetadataFromJpeg(
            $this->buildJpeg(
                $this->segment(self::MARKER_APP2, $iccSegment),
            ),
        );

        self::assertCount(1, $metadata->iccSegments);
        self::assertSame($iccProfile, $metadata->iccProfile);

        $structured = $metadata->structured();
        self::assertSame($description, $structured->technical->colorProfile->profileName);
        self::assertSame('2.4', $structured->technical->colorProfile->profileVersion);
        self::assertSame('XYZ ', $structured->technical->colorProfile->pcs);
        self::assertSame('Perceptual', $structured->technical->colorProfile->renderingIntent);
    }

    /**
     * Builds a JPEG with a multi-segment ICC profile split across two APP2 segments
     * and verifies correct reassembly and ColorProfile population.
     */
    #[Test]
    public function readJpegWithMultiSegmentIccReassemblesProfile(): void
    {
        $description = 'Adobe RGB (1998)';
        $iccProfile  = $this->buildIccProfile($description);

        // Split the profile into two halves
        $midpoint = (int) (strlen($iccProfile) / 2);
        $chunk1   = substr($iccProfile, 0, $midpoint);
        $chunk2   = substr($iccProfile, $midpoint);

        $segment1 = $this->buildIccSegment($chunk1, sequenceNumber: 1, sequenceCount: 2);
        $segment2 = $this->buildIccSegment($chunk2, sequenceNumber: 2, sequenceCount: 2);

        $metadata = $this->readMetadataFromJpeg(
            $this->buildJpeg(
                $this->segment(self::MARKER_APP2, $segment1),
                $this->segment(self::MARKER_APP2, $segment2),
            ),
        );

        self::assertCount(2, $metadata->iccSegments);
        self::assertSame($iccProfile, $metadata->iccProfile);

        $structured = $metadata->structured();
        self::assertSame($description, $structured->technical->colorProfile->profileName);
        self::assertSame('2.4', $structured->technical->colorProfile->profileVersion);
        self::assertSame('XYZ ', $structured->technical->colorProfile->pcs);
        self::assertSame('Perceptual', $structured->technical->colorProfile->renderingIntent);
    }

    /**
     * Verifies that a JPEG without APP2 ICC segments leaves ColorProfile fields at their defaults.
     */
    #[Test]
    public function readJpegWithoutIccLeavesColorProfileEmpty(): void
    {
        $metadata = $this->readMetadataFromJpeg($this->buildJpeg());

        self::assertSame([], $metadata->iccSegments);
        self::assertNull($metadata->iccProfile);
        self::assertNull($metadata->structured()->technical->colorProfile->profileName);
        self::assertNull($metadata->structured()->technical->colorProfile->profileVersion);
        self::assertNull($metadata->structured()->technical->colorProfile->pcs);
    }

    /**
     * Builds a minimal but valid ICC profile with a 'desc' tag containing the given description.
     *
     * The profile uses ICC v2.4 format with 'acsp' signature at offset 36 and a legacy
     * descType tag for the profile description.
     *
     * @param string $description ASCII profile description text.
     *
     * @return string Complete binary ICC profile.
     */
    private function buildIccProfile(string $description): string
    {
        // Build the desc tag payload: 'desc' + reserved(4) + asciiLength(4) + ascii + NUL
        $asciiText   = $description . "\0";
        $asciiLength = strlen($asciiText);
        $descPayload = 'desc'
            . "\0\0\0\0"
            . pack('N', $asciiLength)
            . $asciiText;

        // Pad desc payload to 4-byte boundary per ICC.1:2022 section 7.3
        $descPadding = (4 - (strlen($descPayload) % 4)) % 4;
        $descPayload .= str_repeat("\0", $descPadding);

        // Tag table: 1 tag entry
        $tagCount = 1;
        $tagTable = pack('N', $tagCount);

        // Tag record: signature(4) + offset(4) + size(4)
        // Tag data starts after header(128) + tagCount(4) + tagRecords(12 * tagCount)
        $tagDataOffset = 128 + 4 + (12 * $tagCount);

        // Align tag data offset to 4-byte boundary
        $tagDataOffset = (int) (ceil($tagDataOffset / 4) * 4);

        $tagTable .= 'desc'
            . pack('N', $tagDataOffset)
            . pack('N', strlen($descPayload));

        // Pad tag table to reach the tag data offset
        $headerAndTableSize = 128 + strlen($tagTable);
        $tablePadding       = $tagDataOffset - $headerAndTableSize;

        if ($tablePadding < 0) {
            $tablePadding = 0;
        }

        // Total profile size
        $profileSize = $tagDataOffset + strlen($descPayload);

        // Build the 128-byte header
        $header = pack('N', $profileSize);          // 0-3:   Profile size
        $header .= 'lcms';                           // 4-7:   CMM type
        $header .= chr(2) . chr(0x40) . "\0\0";     // 8-11:  Version 2.4.0
        $header .= 'mntr';                            // 12-15: Profile class (monitor)
        $header .= 'RGB ';                            // 16-19: Color space
        $header .= 'XYZ ';                            // 20-23: PCS
        $header .= pack('n', 2024)                    // 24-25: Year
            . pack('n', 1)                             // 26-27: Month
            . pack('n', 15)                            // 28-29: Day
            . pack('n', 12)                            // 30-31: Hour
            . pack('n', 0)                             // 32-33: Minute
            . pack('n', 0);                            // 34-35: Second
        $header .= 'acsp';                             // 36-39: Profile file signature
        $header .= 'APPL';                             // 40-43: Primary platform (Apple)
        $header .= pack('N', 0);                       // 44-47: Profile flags
        $header .= "\0\0\0\0";                         // 48-51: Device manufacturer
        $header .= "\0\0\0\0";                         // 52-55: Device model
        $header .= str_repeat("\0", 8);                // 56-63: Device attributes
        $header .= pack('N', 0);                       // 64-67: Rendering intent (Perceptual)

        // 68-79: PCS illuminant (D50: X=0.9642, Y=1.0000, Z=0.8249 as s15Fixed16)
        $header .= $this->s15Fixed16(0.9642);
        $header .= $this->s15Fixed16(1.0);
        $header .= $this->s15Fixed16(0.8249);

        $header .= "\0\0\0\0";                         // 80-83: Profile creator
        $header .= str_repeat("\0", 16);                // 84-99: Profile ID
        $header .= str_repeat("\0", 28);                // 100-127: Reserved

        // Ensure header is exactly 128 bytes
        $header = str_pad($header, 128, "\0");

        return $header . $tagTable . str_repeat("\0", $tablePadding) . $descPayload;
    }

    /**
     * Wraps ICC profile data as an APP2 segment payload with the ICC_PROFILE signature.
     *
     * @param string $data           Raw ICC profile data or chunk.
     * @param int    $sequenceNumber Chunk sequence number (1-based).
     * @param int    $sequenceCount  Total number of chunks.
     *
     * @return string APP2 segment payload with ICC_PROFILE header.
     */
    private function buildIccSegment(
        string $data,
        int $sequenceNumber,
        int $sequenceCount,
    ): string {
        return "ICC_PROFILE\0"
            . chr($sequenceNumber)
            . chr($sequenceCount)
            . $data;
    }

    /**
     * Encodes a float as s15Fixed16Number (4 bytes, big-endian).
     *
     * @param float $value Floating-point value to encode.
     *
     * @return string 4-byte big-endian s15Fixed16Number.
     */
    private function s15Fixed16(float $value): string
    {
        $fixed = (int) round($value * 65536.0);

        // Convert negative to unsigned 32-bit representation
        if ($fixed < 0) {
            $fixed += 0x100000000;
        }

        return pack('N', $fixed);
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
