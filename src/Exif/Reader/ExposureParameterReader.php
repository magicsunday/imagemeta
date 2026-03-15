<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;

use function is_int;

/**
 * Reads core exposure parameters (time, aperture, shutter speed, brightness,
 * EV) from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7 defines the picture-taking condition tags decoded by this reader.
 */
final readonly class ExposureParameterReader
{
    /**
     * @param IfdValueReader  $reader     Value reader for IFD tag extraction.
     * @param ValueConverters $converters Value converter facade for EXIF type normalization.
     * @param Ifd|null        $exifIfd    Sub IFD containing EXIF-specific tags.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private ?Ifd $exifIfd,
    ) {
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime)
     */
    public function exposureTime(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::EXPOSURE_TIME);
    }

    /**
     * Returns the exposure time as a human-readable string like "1/50".
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime) stores exposure as RATIONAL seconds.
     * Formats short exposures as fractions and longer exposures as decimal seconds.
     */
    public function exposureTimeFormatted(): ?string
    {
        $seconds = $this->exposureTime();

        return $this->converters->formatExposureTime($seconds);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * EXIF 3.0 §4.6.6.7.2 (FNumber)
     */
    public function fNumber(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::F_NUMBER);
    }

    /**
     * Returns the camera exposure program enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.3 (ExposureProgram)
     */
    public function exposureProgram(): ?ExposureProgram
    {
        // EXIF 3.0 §4.6.6.7.3: default is 0 (Not defined).
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::EXPOSURE_PROGRAM) ?? 0;

        return ExposureProgram::fromExifValue($value);
    }

    /**
     * Returns the APEX shutter speed value when available.
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue)
     */
    public function shutterSpeedValue(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);
    }

    /**
     * Returns the shutter speed in seconds derived from the APEX value.
     */
    public function shutterSpeedSeconds(): ?float
    {
        $raw = $this->reader->normalizedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if ($raw === null) {
            return null;
        }

        return $this->converters->apexShutterSpeedToSeconds($raw);
    }

    /**
     * Returns the APEX shutter speed as a human-readable string like "1/20".
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue) stores APEX shutter speed.
     * This converts the APEX value to a fraction or decimal seconds format.
     */
    public function shutterSpeedFormatted(): ?string
    {
        $raw = $this->reader->normalizedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if ($raw === null) {
            return null;
        }

        return $this->converters->formatShutterSpeedFromApex($raw);
    }

    /**
     * Returns the APEX aperture value when present.
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue)
     */
    public function apertureValue(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::APERTURE_VALUE);
    }

    /**
     * Returns the APEX aperture value as a human-readable f-number string like "f/1.9".
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue) stores APEX aperture.
     * This converts the APEX value to an f-number display format.
     */
    public function apertureValueFormatted(): ?string
    {
        $raw = $this->reader->normalizedValue($this->exifIfd, ExifTag::APERTURE_VALUE);

        if ($raw === null) {
            return null;
        }

        return $this->converters->formatApertureFromApex($raw);
    }

    /**
     * Returns the scene brightness value (APEX) if present.
     *
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue)
     */
    public function brightnessValue(): ?float
    {
        $value = $this->reader->normalizedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

        if ($this->isUnknownBrightness($value)) {
            return null;
        }

        return $this->converters->rationalToFloat($value);
    }

    /**
     * Returns the APEX brightness value as a human-readable decimal string.
     *
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue) stores APEX brightness.
     * This converts the APEX value to a simple decimal format like "-2.21".
     */
    public function brightnessValueFormatted(): ?string
    {
        $value = $this->reader->normalizedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

        if ($this->isUnknownBrightness($value)) {
            return null;
        }

        return $this->converters->formatBrightnessValue($value);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * EXIF 3.0 §4.6.6.7.16 (ExposureBiasValue)
     */
    public function exposureBias(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::EXPOSURE_BIAS_VALUE);
    }

    /**
     * Returns the maximum aperture value (APEX) if present.
     *
     * EXIF 3.0 §4.6.6.7.17 (MaxApertureValue) encodes a single RATIONAL representing
     * the lens's smallest F number expressed as an APEX value.
     */
    public function maxApertureApex(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::MAX_APERTURE_VALUE);
    }

    /**
     * Returns the exposure mode enum indicating manual or auto settings.
     *
     * EXIF 3.0 §4.6.6.7.36 (ExposureMode)
     */
    public function exposureMode(): ?ExposureMode
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::EXPOSURE_MODE);

        return ExposureMode::fromExifValue($value);
    }

    /**
     * Returns the digital zoom ratio when encoded by the camera.
     *
     * EXIF 3.0 §4.6.6.7.38 (DigitalZoomRatio)
     * A ratio with a numerator of zero indicates that digital zoom was not used.
     */
    public function digitalZoomRatio(): ?float
    {
        $ratio = $this->reader->rational($this->exifIfd, ExifTag::DIGITAL_ZOOM_RATIO);

        if ($ratio === 0.0) {
            return null;
        }

        return $ratio;
    }

    /**
     * Returns the exposure index value.
     *
     * EXIF 3.0 §4.6.6.7.30 (ExposureIndex)
     */
    public function exposureIndex(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::EXPOSURE_INDEX);
    }

    // ── Private helpers ─────────────────────────────────────────

    /**
     * Indicates whether a brightness value is marked as unknown.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value.
     *
     * @return bool True when the value is the "unknown" sentinel.
     */
    private function isUnknownBrightness(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): bool {
        if ($value instanceof ExifRational) {
            return $value->numerator === -1;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof ExifRational) {
                return $first->numerator === -1;
            }

            return false;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof UInt64) {
                return $first->toInt('BrightnessValue') === -1;
            }

            return $first === -1;
        }

        if (is_int($value)) {
            return $value === -1;
        }

        return false;
    }
}
