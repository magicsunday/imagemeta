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
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Convenience\CaptureDateResolver
 */
#[CoversClass(CaptureDateResolver::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(XmpDocument::class)]
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
            xmpDoc: null,
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
            xmpDoc: null,
        );

        $result = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-05-01T12:34:56+00:00', $result->format(DATE_ATOM));
    }
}
