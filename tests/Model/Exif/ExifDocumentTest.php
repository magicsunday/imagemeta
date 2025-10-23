<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ExifDocument
 */
#[CoversClass(ExifDocument::class)]
final class ExifDocumentTest extends TestCase
{
    #[Test]
    public function exposesRepresentativeExifValues(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 1, "Canon\0"),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 6),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
            ExifTag::EXPOSURE_TIME            => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [1, 125]),
            ExifTag::F_NUMBER                 => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [28, 10]),
            ExifTag::FOCAL_LENGTH             => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [50, 1]),
            ExifTag::LENS_MODEL               => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'RF50mm F1.2L USM'),
            ExifTag::DATETIME_ORIGINAL        => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL     => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[40, 1], [26, 1], [3000, 100]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[79, 1], [58, 1], [6000, 100]]),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [123, 1]),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, $gpsIfd, null, null);

        self::assertSame('Canon', $doc->cameraMake());
        self::assertSame('EOS R5', $doc->cameraModel());
        self::assertSame('RF50mm F1.2L USM', $doc->lensModel());
        self::assertSame(6, $doc->orientation());
        self::assertSame(200, $doc->iso());
        self::assertSame(0.008, $doc->exposureTime());
        self::assertSame(2.8, $doc->fNumber());
        self::assertSame(50.0, $doc->focalLengthMm());
        self::assertSame('2024:05:01 12:34:56', $doc->dateTimeOriginalRaw());
        self::assertSame('+02:00', $doc->offsetTimeOriginalRaw());

        $capture = $doc->captureDateTime();
        self::assertNotNull($capture);
        self::assertSame('2024-05-01T12:34:56+02:00', $capture->format(DATE_ATOM));

        $gps = $doc->gps();
        self::assertEqualsWithDelta(40.441666, $gps['lat'], 0.000001);
        self::assertEqualsWithDelta(79.983333, $gps['lon'], 0.000001);
        self::assertEquals(123.0, $gps['alt']);
    }
}
