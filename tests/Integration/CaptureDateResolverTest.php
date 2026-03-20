<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Integration;

use Closure;
use DateTimeImmutable;
use MagicSunday\ImageMeta\Convenience\CaptureDateResolver;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\ValueFactory;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
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
use MagicSunday\ImageMeta\Value\RegionCollection;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CaptureDateResolver across EXIF, XMP, and QuickTime timestamp sources.
 * The suite builds structured metadata and verifies the resolved DateTimeImmutable output.
 * It covers precedence rules and fallback behavior when primary tags are missing.
 * These cases ensure capture time selection remains consistent across metadata variants.
 *
 * @internal
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
#[UsesClass(StructuredMetadataBuilder::class)]
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
#[UsesClass(RegionCollection::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(StructuredMetadata::class)]
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

    /**
     * Uses XMP CreateDate when EXIF capture timestamps are absent.
     * This verifies that XMP is considered a fallback source for capture time.
     */
    #[Test]
    public function returnsXmpCreateDateWhenExifIsMissing(): void
    {
        $metadata = $this->metadataWithXmpCreateDate('2024-03-30T12:34:56Z');

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    /**
     * Supplies a non-ISO CreateDate string in XMP.
     * This ensures invalid date strings are ignored rather than parsed loosely.
     */
    #[Test]
    public function ignoresNonIsoCreateDateValues(): void
    {
        $metadata = $this->metadataWithXmpCreateDate('not-a-date');

        self::assertNull((new CaptureDateResolver())->bestCaptureDateTime($metadata));
    }

    /**
     * Provides an array-valued XMP CreateDate with multiple entries.
     * This confirms the resolver picks the first ISO-formatted value.
     */
    #[Test]
    public function acceptsFirstArrayElementWhenIsoString(): void
    {
        $metadata = $this->metadataWithXmpCreateDate([
            '2024-03-30T12:34:56Z',
            '2024-03-30T12:34:56+01:00',
        ]);

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-03-30T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    /**
     * Provides EXIF DateTimeDigitized with a matching offset tag.
     * This confirms EXIF capture time takes precedence over XMP when present.
     */
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
            structuredResolver: $this->createStructuredResolver(),
        );

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-04-05T01:02:03+01:00', $result->format(DATE_ATOM));
    }

    /**
     * Provides an XMP CreateDate without a timezone suffix.
     * This confirms dates omitting the timezone component are accepted as valid ISO 8601.
     */
    #[Test]
    public function acceptsXmpCreateDateWithoutTimezone(): void
    {
        $metadata = $this->metadataWithXmpCreateDate('2024-01-15T10:30:00');

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
        self::assertSame('10:30:00', $result->format('H:i:s'));
    }

    /**
     * Provides an XMP CreateDate with fractional seconds but no timezone suffix.
     * This confirms sub-second precision values are accepted when the timezone is absent.
     */
    #[Test]
    public function acceptsXmpCreateDateWithFractionalSecondsAndNoTimezone(): void
    {
        $metadata = $this->metadataWithXmpCreateDate('2024-01-15T10:30:00.123');

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
        self::assertSame('10:30:00', $result->format('H:i:s'));
    }

    /**
     * Provides an XMP CreateDate using an hour-only timezone offset.
     * This confirms ISO 8601 offsets without minutes are accepted as valid.
     */
    #[Test]
    public function acceptsXmpCreateDateWithHourOnlyTimezoneOffset(): void
    {
        $metadata = $this->metadataWithXmpCreateDate('2024-01-15T10:30:00+05');

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15T10:30:00+05:00', $result->format(DATE_ATOM));
    }

    /**
     * Supplies GPS date and time tags without EXIF or XMP capture dates.
     * This validates GPS timestamps are used as the last-resort source.
     */
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
            structuredResolver: $this->createStructuredResolver(),
        );

        $result = (new CaptureDateResolver())->bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-05-01T12:34:56+00:00', $result->format(DATE_ATOM));
    }

    /**
     * @param string|list<string> $createDate
     */
    private function metadataWithXmpCreateDate(string|array $createDate): Metadata
    {
        return new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: new XmpDocument([
                '{' . self::XMP_NAMESPACE . '}CreateDate' => $createDate,
            ]),
            structuredResolver: $this->createStructuredResolver(),
        );
    }

    /**
     * @return Closure(Metadata): StructuredMetadata
     */
    private function createStructuredResolver(): Closure
    {
        $builder = StructuredMetadataBuilder::createDefault();

        return $builder->assemble(...);
    }
}
