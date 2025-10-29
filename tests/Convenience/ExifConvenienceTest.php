<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Convenience;

use MagicSunday\ImageMeta\Convenience\ExifConvenience;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Convenience\ExifConvenience
 */
#[CoversClass(ExifConvenience::class)]
#[UsesClass(ExifDocument::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class ExifConvenienceTest extends TestCase
{
    /**
     * Provides both modern and legacy ISO sensitivity tags to ensure the helper prefers the
     * EXIF-specific entry and falls back when absent.
     */
    #[Test]
    public function isoPrefersModernTagAndFallsBackToLegacy(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 320),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertSame(320, ExifConvenience::iso($doc));

        $docWithLegacy = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(200, ExifConvenience::iso($docWithLegacy));
    }

    /**
     * Ensures the ISO helper returns the first value from array-based entries rather than the raw
     * array.
     */
    #[Test]
    public function isoExtractsFirstEntryFromArray(): void
    {
        $list = new ExifNumericList([100, 200, 400]);
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                count($list->values),
                $list,
            ),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(100, ExifConvenience::iso($doc));
    }

    /**
     * Ensures ISO values stored as floating point scalars are truncated to integers.
     */
    #[Test]
    public function isoCastsFloatValuesToInteger(): void
    {
        $list = new ExifNumericList([200.0, 400.0]);
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                11,
                count($list->values),
                $list,
            ),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(200, ExifConvenience::iso($doc));
    }

    /**
     * Ensures ISO rational pairs are interpreted via their numerator/denominator representation.
     */
    #[Test]
    public function isoDerivesValueFromRationalPair(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                5,
                1,
                new ExifRational(320, 1),
            ),
        ]);

        $doc = new ExifDocument($ifd0, null, null, null, null);

        self::assertSame(320, ExifConvenience::iso($doc));
    }

    #[Test]
    public function isoParsesIsoPrefixedAsciiValues(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 2, 7, 'ISO 800'),
        ]);

        $doc = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(800, ExifConvenience::iso($doc));
    }

    #[Test]
    public function isoReadsValuesFromSubIfds(): void
    {
        $subIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                640,
            ),
        ]);

        $doc = new ExifDocument(new Ifd([]), null, null, null, null, null, [], [256 => $subIfd]);

        self::assertSame(640, ExifConvenience::iso($doc));
    }

    /**
     * Validates EXIF 3.0 sensitivity metadata honours the documented priority rules and supports
     * files that only populate the newer tags.
     */
    #[Test]
    public function isoRespectsSensitivityTypePriority(): void
    {
        $ifd0 = new Ifd([]);

        $sosOnly = new Ifd([
            ExifTag::SENSITIVITY_TYPE            => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 1),
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 3, 1, 250),
        ]);

        $docWithSosOnly = new ExifDocument($ifd0, $sosOnly, null, null, null);

        self::assertSame(250, ExifConvenience::iso($docWithSosOnly));

        $priorityIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE           => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 6),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::ISO_SPEED                  => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 640),
        ]);

        $docWithPriority = new ExifDocument($ifd0, $priorityIfd, null, null, null);

        self::assertSame(320, ExifConvenience::iso($docWithPriority));
    }

    /**
     * Reads capture timestamp tags and verifies the helper delegates to the document parser to
     * return a timezone-aware DateTime.
     */
    #[Test]
    public function captureDateTimeDelegatesToDocument(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL    => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 1, '+02:00'),
        ]);

        $doc      = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $captured = ExifConvenience::captureDateTime($doc);

        self::assertNotNull($captured);
        self::assertSame('2024-05-01T12:34:56+02:00', $captured->format(DATE_ATOM));
    }

    /**
     * Confirms null is returned when neither the IFD0 nor EXIF IFD provide capture date
     * information.
     */
    #[Test]
    public function captureDateTimeReturnsNullWhenMissing(): void
    {
        $doc = new ExifDocument(new Ifd([]), new Ifd([]), null, null, null);

        self::assertNull(ExifConvenience::captureDateTime($doc));
    }

    /**
     * Provides digitised and modify date tags to confirm the helper relies on the document's
     * fallback search order when DateTimeOriginal is absent.
     */
    #[Test]
    public function captureDateTimeFallsBackToDigitizedAndModifyDates(): void
    {
        $digitizedIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED => new IfdEntry(
                ExifTag::DATETIME_DIGITIZED,
                2,
                19,
                '2023:12:24 06:30:45',
            ),
            ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(
                ExifTag::OFFSET_TIME_DIGITIZED,
                2,
                6,
                '-05:30',
            ),
        ]);

        $docWithDigitized = new ExifDocument(new Ifd([]), $digitizedIfd, null, null, null);

        $digitized = ExifConvenience::captureDateTime($docWithDigitized);

        self::assertNotNull($digitized);
        self::assertSame('2023-12-24T06:30:45-05:30', $digitized->format(DATE_ATOM));

        $modifyDateIfd0 = new Ifd([
            ExifTag::DATETIME => new IfdEntry(
                ExifTag::DATETIME,
                2,
                19,
                '2023:11:10 09:08:07',
            ),
        ]);

        $docWithModifyDate = new ExifDocument($modifyDateIfd0, new Ifd([]), null, null, null);

        $modify = ExifConvenience::captureDateTime($docWithModifyDate);

        self::assertNotNull($modify);
        self::assertSame('2023-11-10T09:08:07+00:00', $modify->format(DATE_ATOM));
    }

    /**
     * Supplies an incomplete timestamp string to ensure the helper refuses values lacking time
     * components.
     */
    #[Test]
    public function captureDateTimeReturnsNullForShortTimestamp(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:01'),
        ]);

        $doc = new ExifDocument($ifd0, $exifIfd, null, null, null);

        self::assertNull(ExifConvenience::captureDateTime($doc));
    }

    /**
     * Ensures the flattened metadata representation exposes the expected shape and value types.
     */
    #[Test]
    public function toArrayReturnsNormalisedShape(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 5, 'Canon'),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 3, 'EOS'),
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 1),
        ]);

        $exifIfd = new Ifd([
            ExifTag::LENS_MODEL           => new IfdEntry(ExifTag::LENS_MODEL, 2, 7, 'EF 50mm'),
            ExifTag::EXPOSURE_TIME        => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [1, 2]),
            ExifTag::F_NUMBER             => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [18, 10]),
            ExifTag::FOCAL_LENGTH         => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [50, 1]),
            ExifTag::ISO_SPEED            => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 200),
            ExifTag::DATETIME_ORIGINAL    => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 19, '2024:05:01 12:34:56'),
            ExifTag::OFFSET_TIME_ORIGINAL => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+02:00'),
        ]);

        $gpsIfd = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[51, 1], [30, 1], [0, 1]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[0, 1], [7, 1], [3000, 100]]),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [450, 10]),
        ]);

        $doc    = new ExifDocument($ifd0, $exifIfd, $gpsIfd, null, null);
        $result = ExifConvenience::toArray($doc);

        $expected = [
            'make'        => 'Canon',
            'model'       => 'EOS',
            'lens'        => 'EF 50mm',
            'orientation' => 1,
            'captured_at' => '2024-05-01T12:34:56+02:00',
            'exposure_s'  => 0.5,
            'fnumber'     => 1.8,
            'focal_mm'    => 50.0,
            'iso'         => 200,
            'gps_lat'     => 51.5,
            'gps_lon'     => 0.125,
            'gps_alt'     => 45.0,
        ];

        self::assertSame($expected, $result);
    }
}
