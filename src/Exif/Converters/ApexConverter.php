<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function fmod;
use function round;
use function rtrim;
use function sprintf;

/**
 * Converts APEX (Additive System of Photographic Exposure) values.
 *
 * EXIF 3.0 §4.6.6.7 defines APEX values for ShutterSpeedValue, ApertureValue,
 * BrightnessValue, ExposureCompensation, and MaxApertureValue.
 */
final readonly class ApexConverter
{
    /**
     * Creates the converter with its rational dependency.
     *
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
    ) {
    }

    /**
     * Converts an APEX aperture value to an f-number.
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue): Av = 2 × log₂(F)
     * Therefore: F = 2^(Av/2)
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value APEX aperture value.
     *
     * @return float|null The f-number or null if conversion fails.
     */
    public function toFNumber(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        $apex = $this->rationalConverter->toFloat($value);

        if ($apex === null) {
            return null;
        }

        return 2 ** ($apex / 2);
    }

    /**
     * Converts an APEX shutter speed value to seconds.
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue): Sv = -log₂(t)
     * Therefore: t = 2^(-Sv) = 1 / 2^Sv
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value APEX shutter speed value.
     *
     * @return float|null Exposure time in seconds or null if conversion fails.
     */
    public function toSeconds(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        $apex = $this->rationalConverter->toFloat($value);

        if ($apex === null) {
            return null;
        }

        return 2 ** (-$apex);
    }

    /**
     * Formats an APEX shutter speed value as a human-readable string.
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue).
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value APEX shutter speed value.
     *
     * @return string|null Formatted string like "1/125" or "2.5" or null.
     */
    public function formatShutterSpeed(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        $seconds = $this->toSeconds($value);

        return $this->formatExposureTime($seconds);
    }

    /**
     * Formats an exposure time in seconds as a human-readable string.
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime).
     *
     * @param float|null $seconds Exposure time in seconds.
     *
     * @return string|null Formatted string like "1/125" or "2.5" or null.
     */
    public function formatExposureTime(?float $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        // For exposures >= 0.5 seconds, show as decimal
        if ($seconds >= 0.5) {
            $rounded = round($seconds, 1);

            if (fmod($rounded, 1.0) === 0.0) {
                return sprintf('%d', (int) $rounded);
            }

            return sprintf('%.1f', $rounded);
        }

        // For shorter exposures, show as fraction 1/x
        $denominator = round(1 / $seconds);

        if ($denominator > 0) {
            return sprintf('1/%d', (int) $denominator);
        }

        return null;
    }

    /**
     * Formats an APEX aperture value as a human-readable f-number string.
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue).
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value APEX aperture value.
     *
     * @return string|null Formatted string like "f/2.8" or null.
     */
    public function formatAperture(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        $fNumber = $this->toFNumber($value);

        return $this->formatFNumber($fNumber);
    }

    /**
     * Formats an f-number as a human-readable string.
     *
     * @param float|null $fNumber The f-number to format.
     *
     * @return string|null Formatted string like "f/2.8" or "f/8" or null.
     */
    public function formatFNumber(?float $fNumber): ?string
    {
        if ($fNumber === null || $fNumber <= 0) {
            return null;
        }

        $rounded = round($fNumber, 1);

        // If it's a whole number, don't show decimal
        if ($rounded === floor($rounded)) {
            return sprintf('f/%d', (int) $rounded);
        }

        return sprintf('f/%.1f', $rounded);
    }

    /**
     * Formats an APEX brightness value as a human-readable string.
     *
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue).
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value APEX brightness value.
     *
     * @return string|null Formatted string like "-2.21" or null.
     */
    public function formatBrightness(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        $brightness = $this->rationalConverter->toFloat($value);

        if ($brightness === null) {
            return null;
        }

        if (($value instanceof ExifRational) && $this->isUnknownBrightnessDenominator($value->denominator)) {
            return null;
        }

        $rounded = round($brightness, 2);

        // Remove trailing zeros after decimal point
        $formatted = sprintf('%.2f', $rounded);
        $formatted = rtrim($formatted, '0');

        return rtrim($formatted, '.');
    }

    /**
     * Determines whether the denominator represents the EXIF unknown brightness sentinel.
     */
    private function isUnknownBrightnessDenominator(int $denominator): bool
    {
        if ($denominator === -1) {
            return true;
        }

        return $denominator === ExifConst::EXIF_UNKNOWN_DENOMINATOR;
    }

    /**
     * Calculates EV100 (Exposure Value at ISO 100) from exposure parameters.
     *
     * @param float|null $exposureTimeSec Exposure time in seconds.
     * @param float|null $fNumber         F-number (aperture).
     * @param int|null   $iso             ISO sensitivity.
     *
     * @return float|null EV100 value or null if calculation fails.
     */
    public function calcEv100(?float $exposureTimeSec, ?float $fNumber, ?int $iso): ?float
    {
        if (($exposureTimeSec === null) || ($fNumber === null) || ($iso === null)) {
            return null;
        }

        if (($exposureTimeSec <= 0) || ($fNumber <= 0) || ($iso <= 0)) {
            return null;
        }

        // EV = log2(f² / t) - log2(ISO / 100)
        return log(($fNumber ** 2) / $exposureTimeSec, 2) - log($iso / 100, 2);
    }
}
