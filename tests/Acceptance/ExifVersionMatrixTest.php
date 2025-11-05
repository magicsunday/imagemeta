<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

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
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type StructuredExpectation from ExifBackfillMatrixTest
 */
#[UsesClass(ByteReader::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Stream::class)]
#[UsesClass(NormalisesOffsets::class)]
#[UsesClass(ReadsBinaryPrimitives::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ExifAssembler::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(FormatDetector::class)]
#[UsesClass(EnumFromIntStringNullable::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesMerger::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(StructuredMetadataCache::class)]
#[UsesClass(JpegExtractor::class)]
#[UsesClass(TiffExifReader::class)]
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
#[UsesClass(FlashInfo::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(Preview::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(Regions::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(Uav::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[CoversClass(MetadataReader::class)]
final class ExifVersionMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @param StructuredExpectation $expectedStructured
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideStructured')]
    public function matchesStructuredExpectations(string $fixture, array $expectedStructured): void
    {
        $metadata = (new MetadataReader())
            ->read(ExifVersionExpectations::path($fixture));

        self::assertStructuredMatches($fixture, $metadata, $expectedStructured);
    }
}
