<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

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
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ExposureParameterReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
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
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
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
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ExposureParameterReader::class)]
#[UsesClass(ValueConverters::class)]
final class ParsedExifApexExamplesTest extends TestCase
{
    /**
     * Provides the MAX_APERTURE_VALUE rational from the EXIF APEX example.
     * Verifies the parsed maxApertureApex matches the expected 2.97 value.
     */
    #[Test]
    public function returnsMaxApertureApexFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::MAX_APERTURE_VALUE, 5, 297, 100);

        self::assertEqualsWithDelta(2.97, $parsedExif->maxApertureApex(), 0.0001);
    }

    /**
     * Supplies an ExposureBiasValue of -1 EV to mirror the EXIF example.
     * Ensures exposureBias returns the signed float without additional conversion.
     */
    #[Test]
    public function returnsExposureBiasFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::EXPOSURE_BIAS_VALUE, 10, -1, 1);

        self::assertSame(-1.0, $parsedExif->exposureBias());
    }

    /**
     * Uses a ShutterSpeedValue APEX of -2, which corresponds to 4 seconds.
     * Confirms shutterSpeedSeconds converts the APEX value into the expected duration.
     */
    #[Test]
    public function returnsShutterSpeedSecondsFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::SHUTTER_SPEED_VALUE, 10, -2, 1);

        self::assertSame(4.0, $parsedExif->shutterSpeedSeconds());
    }

    /**
     * Sets BrightnessValue to 76.00 to exercise the normal numeric range.
     * Verifies brightnessValue returns the rational as a float with the expected precision.
     */
    #[Test]
    public function returnsBrightnessValueFromSpecRange(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::BRIGHTNESS_VALUE, 10, 7600, 100);

        self::assertEqualsWithDelta(76.0, $parsedExif->brightnessValue(), 0.0001);
    }

    /**
     * Supplies a BrightnessValue of -1, which indicates "unknown" per EXIF.
     * Ensures brightnessValue returns null when the sentinel value is present.
     */
    #[Test]
    public function returnsNullWhenBrightnessValueIsUnknown(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::BRIGHTNESS_VALUE, 10, -1, 1);

        self::assertNull($parsedExif->brightnessValue());
    }

    /**
     * Supplies an ApertureValue of 5 to match the EXIF example.
     * Confirms apertureValue returns the expected float value.
     */
    #[Test]
    public function returnsApertureFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::APERTURE_VALUE, 5, 5, 1);

        self::assertSame(5.0, $parsedExif->apertureValue());
    }

    /**
     * Supplies an FNumber of 2.8 encoded as a rational.
     * Verifies fNumber returns the expected floating-point value.
     */
    #[Test]
    public function returnsFNumberFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::F_NUMBER, 5, 28, 10);

        self::assertSame(2.8, $parsedExif->fNumber());
    }

    /**
     * Uses an ExposureTime rational of 1/400 to represent a fast shutter.
     * Ensures exposureTime returns the correct seconds value with high precision.
     */
    #[Test]
    public function returnsExposureTimeFromSpecExample(): void
    {
        $parsedExif = $this->parsedExifWithRational(ExifTag::EXPOSURE_TIME, 5, 1, 400);

        self::assertEqualsWithDelta(0.0025, $parsedExif->exposureTime(), 0.0000001);
    }

    private function parsedExifWithRational(int $tag, int $type, int $numerator, int $denominator): ParsedExif
    {
        $exifIfd = new Ifd([
            $tag => new IfdEntry($tag, $type, 1, new ExifRational($numerator, $denominator)),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
