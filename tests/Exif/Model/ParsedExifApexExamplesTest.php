<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ParsedExif APEX-related tags using reference values from the EXIF examples.
 * It verifies that max aperture and exposure bias values are converted from rationals.
 * The suite confirms the parser returns floats that match the documented sample outputs.
 * This keeps APEX conversions aligned with the specification examples.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifApexExamplesTest extends TestCase
{
    /**
     * Provides the MAX_APERTURE_VALUE rational from the EXIF APEX example.
     * Verifies the parsed maxApertureApex matches the expected 2.97 value.
     *
     * @return void
     */
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

    /**
     * Supplies an ExposureBiasValue of -1 EV to mirror the EXIF example.
     * Ensures exposureBias returns the signed float without additional conversion.
     *
     * @return void
     */
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

    /**
     * Uses a ShutterSpeedValue APEX of -2, which corresponds to 4 seconds.
     * Confirms shutterSpeedSeconds converts the APEX value into the expected duration.
     *
     * @return void
     */
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

    /**
     * Sets BrightnessValue to 76.00 to exercise the normal numeric range.
     * Verifies brightnessValue returns the rational as a float with the expected precision.
     *
     * @return void
     */
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

    /**
     * Supplies a BrightnessValue of -1, which indicates "unknown" per EXIF.
     * Ensures brightnessValue returns null when the sentinel value is present.
     *
     * @return void
     */
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

    /**
     * Supplies an ApertureValue of 5 to match the EXIF example.
     * Confirms apertureValue returns the expected float value.
     *
     * @return void
     */
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

    /**
     * Supplies an FNumber of 2.8 encoded as a rational.
     * Verifies fNumber returns the expected floating-point value.
     *
     * @return void
     */
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

    /**
     * Uses an ExposureTime rational of 1/400 to represent a fast shutter.
     * Ensures exposureTime returns the correct seconds value with high precision.
     *
     * @return void
     */
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
