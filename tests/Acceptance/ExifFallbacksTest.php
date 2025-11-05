<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\MetadataReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataReader::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\ByteReader::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\ExifCapabilities::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\MemoryBuffer::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\Stream::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\Util\UInt64::class)]
#[UsesClass(\MagicSunday\ImageMeta\Core\Util\Unpack::class)]
#[UsesClass(\MagicSunday\ImageMeta\Curate\ExifAssembler::class)]
#[UsesClass(\MagicSunday\ImageMeta\Curate\Exif\ValueFactory::class)]
#[UsesClass(\MagicSunday\ImageMeta\Curate\StructuredMetadata::class)]
#[UsesClass(\MagicSunday\ImageMeta\Detect\FormatDetector::class)]
#[UsesClass(\MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\CanonDecoder::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\Registry::class)]
#[UsesClass(\MagicSunday\ImageMeta\MakerNotes\RegistryFactory::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\ExifRational::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\ExifRationalList::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\Ifd::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\IfdEntry::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\ParsedExif::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Exif\ValueConverters::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Metadata::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\StructuredMetadataCache::class)]
#[UsesClass(\MagicSunday\ImageMeta\Model\Xmp\XmpDocument::class)]
#[UsesClass(\MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor::class)]
#[UsesClass(\MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader::class)]
#[UsesClass(\MagicSunday\ImageMeta\Parse\Xmp\XmpParser::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Audio::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\AudioClips::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Author::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Camera::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Capture::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\ColorProfile::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\CompositeImageInfo::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Container::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Derived::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Device::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\ExifFlash::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Exposure::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\File::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\FlashInfo::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\FlashPix::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Focus::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Gps::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\GpsCoordinate::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Image::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Integrity::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Interop::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Keywords::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Lens::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Motion::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\MultiPicture::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Preview::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\ProcessingSettings::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Regions::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\RelatedAssets::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Rights::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Scene::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Sensor::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Standards::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Temporal::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\TiffData::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Uav::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Video::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\WhiteBalanceDetails::class)]
#[UsesClass(\MagicSunday\ImageMeta\Value\Xmp::class)]
final class ExifFallbacksTest extends TestCase
{
    private const string SAMPLE = __DIR__ . '/../../test-images/Images/gps_exif_example.jpg';

    #[Test]
    public function readsBestEffortExposureAndTemporalData(): void
    {
        $structured = (new MetadataReader())
            ->read(self::SAMPLE)
            ->structured();

        self::assertSame(80, $structured->exposure->iso);
        self::assertSame(
            '2011-12-06T11:08:37+00:00',
            $structured->temporal->original?->format(DATE_ATOM),
        );
        self::assertSame(
            '400 N Michigan Ave, Chicago, IL 60611, USA',
            $structured->image->userComment,
        );
        self::assertSame('ASCII', $structured->image->userCommentEncoding);

        $interop = $structured->interop;
        self::assertSame('R98', $interop->index);
        self::assertSame('0100', $interop->version);
        self::assertSame(4000, $interop->relatedImageWidth);
        self::assertSame(3000, $interop->relatedImageLength);
    }
}
