<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates APEX value conversions for aperture, shutter speed, brightness, and EV100.
 * It verifies correct EXIF 3.0 §4.6.6.7 formulas and safe null handling for invalid inputs.
 *
 * @internal
 */
#[CoversClass(ApexConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ExifConst::class)]
final class ApexConverterTest extends TestCase
{
    private ApexConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new ApexConverter(
            new RationalConverter(new NumericConverter()),
        );
    }

    /**
     * APEX aperture value 0 yields f/1.0 because 2^(0/2) = 1.
     */
    #[Test]
    public function toFNumberReturnsOneForApexZero(): void
    {
        self::assertEqualsWithDelta(1.0, $this->converter->toFNumber(0), 0.0001);
    }

    /**
     * APEX aperture value 5.6568 yields approximately f/5.6.
     */
    #[Test]
    public function toFNumberConvertsKnownApexApertureValue(): void
    {
        // Av = 2 * log2(5.6) ≈ 4.9726
        // F = 2^(4.9726/2) ≈ 5.6
        $apex = new ExifRational(49726, 10000);
        self::assertEqualsWithDelta(5.6, $this->converter->toFNumber($apex), 0.01);
    }

    /**
     * Null input for toFNumber returns null.
     */
    #[Test]
    public function toFNumberReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toFNumber(null));
    }

    /**
     * APEX shutter speed value 0 yields 1 second exposure (2^(-0) = 1).
     */
    #[Test]
    public function toSecondsReturnsOneForApexZero(): void
    {
        self::assertEqualsWithDelta(1.0, $this->converter->toSeconds(0), 0.0001);
    }

    /**
     * APEX shutter speed value 7 yields 1/128 seconds.
     */
    #[Test]
    public function toSecondsConvertsKnownShutterSpeedValue(): void
    {
        // Sv = 7, t = 2^(-7) = 1/128 = 0.0078125
        self::assertEqualsWithDelta(0.0078125, $this->converter->toSeconds(7), 0.000001);
    }

    /**
     * Null input for toSeconds returns null.
     */
    #[Test]
    public function toSecondsReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toSeconds(null));
    }

    /**
     * formatShutterSpeed formats a fractional exposure correctly.
     */
    #[Test]
    public function formatShutterSpeedReturnsFraction(): void
    {
        // Sv = 7 -> t = 1/128 -> "1/128"
        self::assertSame('1/128', $this->converter->formatShutterSpeed(7));
    }

    /**
     * formatShutterSpeed returns null when input is null.
     */
    #[Test]
    public function formatShutterSpeedReturnsNullForNull(): void
    {
        self::assertNull($this->converter->formatShutterSpeed(null));
    }

    /**
     * formatExposureTime formats a long exposure as a decimal.
     */
    #[Test]
    public function formatExposureTimeShowsDecimalForLongExposures(): void
    {
        self::assertSame('2', $this->converter->formatExposureTime(2.0));
    }

    /**
     * formatExposureTime returns null for zero seconds.
     */
    #[Test]
    public function formatExposureTimeReturnsNullForZero(): void
    {
        self::assertNull($this->converter->formatExposureTime(0.0));
    }

    /**
     * formatExposureTime returns null for negative seconds.
     */
    #[Test]
    public function formatExposureTimeReturnsNullForNegative(): void
    {
        self::assertNull($this->converter->formatExposureTime(-1.0));
    }

    /**
     * formatExposureTime shows fractional seconds with one decimal place.
     */
    #[Test]
    public function formatExposureTimeShowsDecimalWithOnePlace(): void
    {
        self::assertSame('0.5', $this->converter->formatExposureTime(0.5));
    }

    /**
     * formatAperture formats a known APEX aperture value as an f-number string.
     */
    #[Test]
    public function formatApertureReturnsFormattedFNumber(): void
    {
        // Av = 6 -> F = 2^(6/2) = 2^3 = 8 -> "f/8"
        self::assertSame('f/8', $this->converter->formatAperture(6));
    }

    /**
     * formatAperture returns null for null input.
     */
    #[Test]
    public function formatApertureReturnsNullForNull(): void
    {
        self::assertNull($this->converter->formatAperture(null));
    }

    /**
     * formatFNumber formats a fractional f-number with one decimal place.
     */
    #[Test]
    public function formatFNumberShowsDecimalForFractional(): void
    {
        self::assertSame('f/2.8', $this->converter->formatFNumber(2.8));
    }

    /**
     * formatFNumber formats a whole f-number without decimal.
     */
    #[Test]
    public function formatFNumberShowsWholeNumber(): void
    {
        self::assertSame('f/4', $this->converter->formatFNumber(4.0));
    }

    /**
     * formatFNumber returns null for zero.
     */
    #[Test]
    public function formatFNumberReturnsNullForZero(): void
    {
        self::assertNull($this->converter->formatFNumber(0.0));
    }

    /**
     * formatFNumber returns null for negative values.
     */
    #[Test]
    public function formatFNumberReturnsNullForNegative(): void
    {
        self::assertNull($this->converter->formatFNumber(-2.0));
    }

    /**
     * formatBrightness returns a formatted brightness value.
     */
    #[Test]
    public function formatBrightnessReturnsFormattedValue(): void
    {
        self::assertSame('-2.21', $this->converter->formatBrightness(new ExifRational(-221, 100)));
    }

    /**
     * formatBrightness returns null for unknown brightness sentinel denominator.
     */
    #[Test]
    public function formatBrightnessReturnsNullForUnknownDenominator(): void
    {
        self::assertNull($this->converter->formatBrightness(new ExifRational(0, ExifConst::EXIF_UNKNOWN_DENOMINATOR)));
    }

    /**
     * formatBrightness returns null for denominator -1 (unknown sentinel).
     */
    #[Test]
    public function formatBrightnessReturnsNullForNegativeOneDenominator(): void
    {
        self::assertNull($this->converter->formatBrightness(new ExifRational(0, -1)));
    }

    /**
     * formatBrightness returns null for null input.
     */
    #[Test]
    public function formatBrightnessReturnsNullForNull(): void
    {
        self::assertNull($this->converter->formatBrightness(null));
    }

    /**
     * formatBrightness strips trailing zeros.
     */
    #[Test]
    public function formatBrightnessStripsTrailingZeros(): void
    {
        self::assertSame('3', $this->converter->formatBrightness(new ExifRational(300, 100)));
    }

    /**
     * calcEv100 returns the correct EV100 for known exposure parameters.
     */
    #[Test]
    public function calcEv100ReturnsCorrectValue(): void
    {
        // EV100 = log2(8^2 / (1/125)) - log2(100/100) = log2(8000) ≈ 12.97
        self::assertEqualsWithDelta(12.97, $this->converter->calcEv100(1.0 / 125, 8.0, 100), 0.01);
    }

    /**
     * calcEv100 returns null when any parameter is null.
     */
    #[Test]
    public function calcEv100ReturnsNullForNullParameters(): void
    {
        self::assertNull($this->converter->calcEv100(null, 8.0, 100));
        self::assertNull($this->converter->calcEv100(1.0 / 125, null, 100));
        self::assertNull($this->converter->calcEv100(1.0 / 125, 8.0, null));
    }

    /**
     * calcEv100 returns null when any parameter is zero or negative.
     */
    #[Test]
    public function calcEv100ReturnsNullForZeroOrNegativeParameters(): void
    {
        self::assertNull($this->converter->calcEv100(0.0, 8.0, 100));
        self::assertNull($this->converter->calcEv100(1.0 / 125, 0.0, 100));
        self::assertNull($this->converter->calcEv100(1.0 / 125, 8.0, 0));
        self::assertNull($this->converter->calcEv100(-1.0, 8.0, 100));
    }

    /**
     * toFNumber handles a zero-denominator rational gracefully.
     */
    #[Test]
    public function toFNumberReturnsNullForZeroDenominator(): void
    {
        self::assertNull($this->converter->toFNumber(new ExifRational(1, 0)));
    }
}
