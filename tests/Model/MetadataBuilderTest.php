<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model;

use Closure;
use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Core\Util\StringUtil;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\PhotoCalculator;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
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
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\MetadataBuilder;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use MagicSunday\ImageMeta\Parse\FlashPix\FlashPixParser;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
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
use MagicSunday\ImageMeta\Value\UserComment;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Verifies MetadataBuilder wiring for structured metadata assembly dependencies.
 */
#[CoversClass(MetadataBuilder::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(DateTimeUtil::class)]
#[UsesClass(StringUtil::class)]
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
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
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
#[UsesClass(ValueFactory::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(FlashPixDocument::class)]
#[UsesClass(FlashPixParser::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccParser::class)]
#[UsesClass(IccTagDecoder::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(Author::class)]
#[UsesClass(Camera::class)]
#[UsesClass(Capture::class)]
#[UsesClass(CaptureHardware::class)]
#[UsesClass(CaptureSettings::class)]
#[UsesClass(ColorProfile::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(CreatorContact::class)]
#[UsesClass(DepthMap::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Device::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(ExposureAdjustments::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(File::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(Gps::class)]
#[UsesClass(HdrGainMap::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Iptc::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(LocationTime::class)]
#[UsesClass(MediaContent::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(Provenance::class)]
#[UsesClass(RegionCollection::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(TechnicalData::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(TiffColorRef::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(TiffLayout::class)]
#[UsesClass(TiffStructure::class)]
#[UsesClass(UserComment::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
final class MetadataBuilderTest extends TestCase
{
    #[Test]
    public function injectsStructuredResolverIntoBuiltMetadata(): void
    {
        $resolver = $this->createStructuredResolver();
        $builder  = new MetadataBuilder($resolver);
        $first    = $builder->withFileIdentity(extension: 'jpg')->build();
        $second   = $builder->withFileIdentity(extension: 'heic')->build();

        $resolverProperty = new ReflectionProperty(Metadata::class, 'structuredResolver');
        $firstResolver    = $resolverProperty->getValue($first);
        $secondResolver   = $resolverProperty->getValue($second);

        self::assertInstanceOf(Closure::class, $firstResolver);
        self::assertInstanceOf(Closure::class, $secondResolver);
        self::assertNotSame($firstResolver, $secondResolver);
    }

    #[Test]
    public function preservesPerMetadataStructuredResultsWhenBuilderIsShared(): void
    {
        $resolver = $this->createStructuredResolver();
        $builder  = new MetadataBuilder($resolver);
        $first    = $builder->withFileIdentity(extension: 'jpg')->build();
        $second   = $builder->withFileIdentity(extension: 'heic')->build();

        self::assertSame('jpg', $first->structured()->provenance->file->extension);
        self::assertSame('heic', $second->structured()->provenance->file->extension);
    }

    /**
     * @return Closure(Metadata):StructuredMetadata
     */
    private function createStructuredResolver(): Closure
    {
        $assembler = StructuredMetadataBuilder::createDefault();

        return $assembler->assemble(...);
    }
}
