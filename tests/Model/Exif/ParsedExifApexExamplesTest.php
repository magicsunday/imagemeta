<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifApexExamplesTest extends TestCase
{
    #[Test]
    public function returnsMaxApertureApexFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::MAX_APERTURE_VALUE => new IfdEntry(
                ExifTag::MAX_APERTURE_VALUE,
                5,
                1,
                new ExifRational(297, 100),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertEqualsWithDelta(2.97, $parsedExif->maxApertureApex(), 0.0001);
    }

    #[Test]
    public function returnsExposureBiasFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_BIAS_VALUE => new IfdEntry(
                ExifTag::EXPOSURE_BIAS_VALUE,
                10,
                1,
                new ExifRational(-1, 1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(-1.0, $parsedExif->exposureBias());
    }

    #[Test]
    public function returnsShutterSpeedSecondsFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SHUTTER_SPEED_VALUE => new IfdEntry(
                ExifTag::SHUTTER_SPEED_VALUE,
                10,
                1,
                new ExifRational(-2, 1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(4.0, $parsedExif->shutterSpeedSeconds());
    }

    #[Test]
    public function returnsBrightnessValueFromSpecRange(): void
    {
        $exifIfd = new Ifd([
            ExifTag::BRIGHTNESS_VALUE => new IfdEntry(
                ExifTag::BRIGHTNESS_VALUE,
                10,
                1,
                new ExifRational(7600, 100),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertEqualsWithDelta(76.0, $parsedExif->brightnessValue(), 0.0001);
    }

    #[Test]
    public function returnsNullWhenBrightnessValueIsUnknown(): void
    {
        $exifIfd = new Ifd([
            ExifTag::BRIGHTNESS_VALUE => new IfdEntry(
                ExifTag::BRIGHTNESS_VALUE,
                10,
                1,
                new ExifRational(-1, 1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->brightnessValue());
    }

    #[Test]
    public function returnsApertureFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::APERTURE_VALUE => new IfdEntry(
                ExifTag::APERTURE_VALUE,
                5,
                1,
                new ExifRational(5, 1),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(5.0, $parsedExif->apertureValue());
    }

    #[Test]
    public function returnsFNumberFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::F_NUMBER => new IfdEntry(
                ExifTag::F_NUMBER,
                5,
                1,
                new ExifRational(28, 10),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(2.8, $parsedExif->fNumber());
    }

    #[Test]
    public function returnsExposureTimeFromSpecExample(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_TIME => new IfdEntry(
                ExifTag::EXPOSURE_TIME,
                5,
                1,
                new ExifRational(1, 400),
            ),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertEqualsWithDelta(0.0025, $parsedExif->exposureTime(), 0.0000001);
    }
}
