<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Convenience\CaptureDateResolver;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Factory\Exif\ValueFactory;
use MagicSunday\ImageMeta\Factory\ExifAssembler;
use MagicSunday\ImageMeta\Factory\StructuredMetadata;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\StructuredMetadataCache;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
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
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaptureDateResolver.
 */
#[CoversClass(CaptureDateResolver::class)]
#[UsesClass(AppleMakerNotes::class)]
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
#[UsesClass(ExifAssembler::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(File::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(Regions::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(StructuredMetadataCache::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
#[UsesClass(XmpDocument::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class CaptureDateResolverTest extends TestCase
{
    private const string XMP_NAMESPACE = 'http://ns.adobe.com/xap/1.0/';

    #[Test]
    public function returnsXmpCreateDateWhenExifIsMissing(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => '2024-03-30T12:34:56Z',
            ]),
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    #[Test]
    public function ignoresNonIsoCreateDateValues(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => 'not-a-date',
            ]),
        );

        self::assertNull(CaptureDateResolver::bestCaptureDateTime($metadata));
    }

    #[Test]
    public function acceptsFirstArrayElementWhenIsoString(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => [
                    '2024-03-30T12:34:56Z',
                    '2024-03-30T12:34:56+01:00',
                ],
            ]),
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    #[Test]
    public function prefersExifCaptureDateWhenAvailable(): void
    {
        $exifDoc = new ParsedExif(
            new Ifd([]),
            new Ifd([
                ExifTag::DATETIME_DIGITIZED => new IfdEntry(
                    ExifTag::DATETIME_DIGITIZED,
                    2,
                    19,
                    '2024:04:05 01:02:03',
                ),
                ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(
                    ExifTag::OFFSET_TIME_DIGITIZED,
                    2,
                    6,
                    '+01:00',
                ),
            ]),
            null,
            null,
            null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
            xmpBlobs: [],
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-04-05T01:02:03+01:00', $result->format(DATE_ATOM));
    }

    #[Test]
    public function usesGpsTimestampWhenCaptureDateMissing(): void
    {
        $timeStamp = new ExifRationalList([
            new ExifRational(12, 1),
            new ExifRational(34, 1),
            new ExifRational(56, 1),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_DATE_STAMP   => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, '2024:05:01'),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(ExifTag::GPS_TIME_STAMP, 5, 3, $timeStamp),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(51, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(0, 1),
                    new ExifRational(7, 1),
                    new ExifRational(3000, 100),
                ]),
            ),
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: new ParsedExif(new Ifd([]), null, $gpsIfd, null, null),
            xmpBlobs: [],
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-05-01T12:34:56+00:00', $result->format(DATE_ATOM));
    }
}
