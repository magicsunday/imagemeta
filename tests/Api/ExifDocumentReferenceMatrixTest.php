<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Curate\Exif\ValueFactory;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\StructuredMetadataCache;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Uav;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type StructuredExpectation array{
 *     standards: array{
 *         exifVersion: ?string,
 *         profile: ?string,
 *         flashpixVersion: ?string,
 *         tiffEpStandardId: array<int|string, mixed>|null,
 *         tiffEpStandardString: ?string,
 *     },
 *     exposure: array{iso: ?int},
 *     capture: array{
 *         dateTimeOriginal: ?string,
 *         offsetTimeOriginal: ?string,
 *         subSecTimeOriginal: ?string,
 *     },
 *     image: array{userComment: ?string, userCommentEncoding: ?string},
 *     interop: array{
 *         index: ?string,
 *         version: ?string,
 *         fileFormat: ?string,
 *         width: ?int,
 *         length: ?int,
 *     },
 *     preview: array{
 *         hasThumbnail: ?bool,
 *         hasPreview: ?bool,
 *         previewOffset: ?int,
 *         previewLength: ?int,
 *         previewWidth: ?int,
 *         previewHeight: ?int,
 *         previewBitDepth: ?int,
 *         previewCompression: ?int,
 *         previewCompressionName: ?string,
 *         previewColorSpace: ?int,
 *         previewColorSpaceName: ?string,
 *         previewEncoding: ?string,
 *         previewMimeType: ?string,
 *         previewScale: ?float,
 *         thumbnailOffset: ?int,
 *         thumbnailLength: ?int,
 *         thumbnailCompression: ?int,
 *         thumbnailCompressionName: ?string,
 *         thumbnailStripOffsets: array<int, int>|null,
 *         thumbnailStripByteCounts: array<int, int>|null,
 *         thumbnailTileOffsets: array<int, int>|null,
 *         thumbnailTileByteCounts: array<int, int>|null,
 *         previewStripOffsets: array<int, int>|null,
 *         previewStripByteCounts: array<int, int>|null,
 *         previewTileOffsets: array<int, int>|null,
 *         previewTileByteCounts: array<int, int>|null,
 *     },
 *     makerNotes: array{vendor: string, length: int, sha1: string, isSafe: ?bool}|null,
 *     environment: array{temperatureC: ?float, humidityPercent: ?float, pressureHpa: ?float},
 *     sensor: array{spatialFrequencyResponse: array<int|string, mixed>|null},
 * }
 * @phpstan-type ApiExpectation array{
 *     iso: ?int,
 *     dateTimeOriginal: ?string,
 *     userComment: ?string,
 *     userCommentEncoding: ?string,
 *     interop: array{
 *         index: ?string,
 *         version: ?string,
 *         fileFormat: ?string,
 *         width: ?int,
 *         length: ?int,
 *     },
 *     preview: array{
 *         hasThumbnail: ?bool,
 *         hasPreview: ?bool,
 *         previewOffset: ?int,
 *         previewLength: ?int,
 *         previewWidth: ?int,
 *         previewHeight: ?int,
 *         previewBitDepth: ?int,
 *         previewCompression: ?int,
 *         previewCompressionName: ?string,
 *         previewColorSpace: ?int,
 *         previewColorSpaceName: ?string,
 *         previewEncoding: ?string,
 *         previewMimeType: ?string,
 *         previewScale: ?float,
 *         thumbnailOffset: ?int,
 *         thumbnailLength: ?int,
 *         thumbnailCompression: ?int,
 *         thumbnailCompressionName: ?string,
 *         thumbnailStripOffsets: array<int, int>|null,
 *         thumbnailStripByteCounts: array<int, int>|null,
 *         thumbnailTileOffsets: array<int, int>|null,
 *         thumbnailTileByteCounts: array<int, int>|null,
 *         previewStripOffsets: array<int, int>|null,
 *         previewStripByteCounts: array<int, int>|null,
 *         previewTileOffsets: array<int, int>|null,
 *         previewTileByteCounts: array<int, int>|null,
 *     },
 * }
 * @phpstan-type ModelExpectation array{
 *     exifVersion: ?string,
 *     exifProfile: string,
 *     flashpixVersion: ?string,
 *     tiffEpStandardId: array<int|string, mixed>|null,
 *     tiffEpStandardString: ?string,
 * }
 *
 * @method static void assertStructuredMatches(string $fixture, Metadata $metadata, StructuredExpectation $expected)
 * @method static void assertApiMatches(string $fixture, StructuredMetadata $document, ApiExpectation $expected)
 * @method static void assertModelMatches(string $fixture, ?ParsedExif $document, ModelExpectation $expected)
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
#[UsesClass(ColorProfile::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Device::class)]
#[UsesClass(ExifAssembler::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(File::class)]
#[UsesClass(FlashInfo::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(FormatDetector::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(JpegExtractor::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(Preview::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(Regions::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(StructuredMetadataCache::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Uav::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[UsesTrait(NormalisesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
final class ExifDocumentReferenceMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @param ApiExpectation $expectedApi
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideApi')]
    public function exposesFallbackMetadataFromReferenceImages(
        string $fixture,
        array $expectedApi,
    ): void {
        $metadata = (new MetadataReader())
            ->read(ExifVersionExpectations::path($fixture));

        $modelDocument = $metadata->exifDoc;
        self::assertNotNull($modelDocument, sprintf('Reference EXIF document missing for %s', $fixture));

        /**
         * @var array{
         *     structured: array<string, mixed>,
         *     api: ApiExpectation,
         *     model: ModelExpectation,
         * } $expectation
         */
        $expectation = ExifVersionExpectations::get($fixture);
        self::assertModelMatches($fixture, $modelDocument, $expectation['model']);

        $structured = $metadata->structured();

        self::assertApiMatches($fixture, $structured, $expectedApi);
    }
}
