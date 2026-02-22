<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\Oecf;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;
use MagicSunday\ImageMeta\Value\SubjectArea;

use function array_slice;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function strlen;
use function substr;

/**
 * Reads exposure, metering, flash, scene, focus, sensitivity and related
 * photographic metadata from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7 defines the picture-taking condition tags decoded by this reader.
 */
final readonly class ExposureExifReader
{
    /**
     * @param IfdValueReader  $reader       Value reader for IFD tag extraction.
     * @param ValueConverters $converters   Value converter facade for EXIF type normalization.
     * @param Ifd             $ifd0         Root IFD of the TIFF structure.
     * @param Ifd|null        $exifIfd      Sub IFD containing EXIF-specific tags.
     * @param Ifd|null        $interopIfd   Sub IFD containing interoperability tags.
     * @param FallbackIfdSet  $fallbackIfds Fallback IFD resolution set.
     * @param Endian          $byteOrder    TIFF byte order.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
        private ?Ifd $interopIfd,
        private FallbackIfdSet $fallbackIfds,
        private Endian $byteOrder,
    ) {
    }

    // ========================================================================
    // Exposure settings
    // ========================================================================

    /**
     * Returns the exposure time in seconds if available.
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime)
     *
     * @return float|null
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
     *
     * @return float|null
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
        $raw = $this->reader->normalisedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

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
        $raw = $this->reader->normalisedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

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
        $raw = $this->reader->normalisedValue($this->exifIfd, ExifTag::APERTURE_VALUE);

        if ($raw === null) {
            return null;
        }

        return $this->converters->formatApertureFromApex($raw);
    }

    /**
     * Returns the scene brightness value (APEX) if present.
     *
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue)
     *
     * @return float|null
     */
    public function brightnessValue(): ?float
    {
        $value = $this->reader->normalisedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

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
        $value = $this->reader->normalisedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

        if ($this->isUnknownBrightness($value)) {
            return null;
        }

        return $this->converters->formatBrightnessValue($value);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * EXIF 3.0 §4.6.6.7.16 (ExposureBiasValue)
     *
     * @return float|null
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
     *
     * @return float|null
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

    // ========================================================================
    // ISO / sensitivity
    // ========================================================================

    /**
     * Returns the declared EXIF sensitivity type as defined by EXIF 3.0 §4.6.6.7.7 Table 14.
     *
     * Signals which ISO 12232 parameter the PhotographicSensitivity tag represents.
     */
    public function sensitivityType(): ?SensitivityType
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::SENSITIVITY_TYPE);

        if ($value === null) {
            return null;
        }

        return SensitivityType::fromExifValue($value);
    }

    /**
     * Returns the standard output sensitivity (SOS) value recorded for the capture.
     *
     * EXIF 3.0 §4.6.6.7.8
     */
    public function standardOutputSensitivity(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY);
    }

    /**
     * Returns the recommended exposure index (REI) value recorded for the capture.
     *
     * EXIF 3.0 §4.6.6.7.9
     */
    public function recommendedExposureIndex(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX);
    }

    /**
     * Returns the ISO speed value when provided separately from photographic sensitivity.
     *
     * EXIF 3.0 §4.6.6.7.10
     */
    public function isoSpeedValue(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::ISO_SPEED);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * EXIF 3.0 §4.6.6.7.7 Table 14 defines how SensitivityType maps the
     * PhotographicSensitivity tag to ISO 12232 parameters and combinations.
     * When declared, the photographic sensitivity value must be prioritised for
     * the selected parameter(s) before falling back to legacy individual tags.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        $sensitivityType = $this->sensitivityType();
        if ($sensitivityType instanceof SensitivityType) {
            foreach ($this->sensitivityTagPriority($sensitivityType) as $tag) {
                $value = $this->reader->int($this->exifIfd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $candidates = [
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::EXPOSURE_INDEX],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->reader->int($ifd, $tag);
            if ($value !== null) {
                return $value;
            }
        }

        $fallbackTags = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::ISO_SPEED,
            ExifTag::EXPOSURE_INDEX,
        ];

        foreach ($this->fallbackIfds->resolve(includeIfd0: true) as $ifd) {
            foreach ($fallbackTags as $tag) {
                $value = $this->reader->int($ifd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the ISO sensitivity using a broader set of fallbacks for non-standard encodings.
     */
    public function isoBestEffort(): ?int
    {
        $iso = $this->iso();
        if ($iso !== null) {
            return $iso;
        }

        $fallbacks = [
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::EXPOSURE_INDEX],
            [$this->ifd0, ExifTag::EXPOSURE_INDEX],
        ];

        foreach ($fallbacks as [$ifd, $tag]) {
            $value = $this->reader->coerceIntValue($this->reader->value($ifd, $tag));
            if ($value !== null) {
                return $value;
            }
        }

        $tagPriority = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::ISO_SPEED,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::EXPOSURE_INDEX,
        ];

        foreach ($this->fallbackIfds->resolve(includePrimaryThumbnail: true, includeIfd0: false) as $ifd) {
            foreach ($tagPriority as $tag) {
                $value = $this->reader->coerceIntValue($this->reader->value($ifd, $tag));
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the ISO latitude yyy value when present and paired with ISOSpeed and ISOSpeedLatitudezzz.
     *
     * EXIF 3.0 §4.6.6.7.11
     */
    public function isoSpeedLatitudeYyy(): ?int
    {
        $latitudeYyy = $this->reader->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_YYY);

        if ($latitudeYyy === null) {
            return null;
        }

        if ($this->isoSpeedValue() === null) {
            return null;
        }

        if ($this->isoSpeedLatitudeZzz() === null) {
            return null;
        }

        return $latitudeYyy;
    }

    /**
     * Returns the ISO latitude zzz value when present.
     *
     * EXIF 3.0 §4.6.6.7.12 (ISOSpeedLatitudezzz)
     */
    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_ZZZ);
    }

    /**
     * Returns the spectral sensitivity description.
     *
     * EXIF 3.0 §4.6.6.7.4 (SpectralSensitivity)
     */
    public function spectralSensitivity(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::SPECTRAL_SENSITIVITY);
    }

    // ========================================================================
    // Flash
    // ========================================================================

    /**
     * Returns the flash status flags if present.
     *
     * @return int|null
     */
    public function flash(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::FLASH);
    }

    /**
     * Returns the decoded flash information value object when present.
     *
     * EXIF 3.0 §4.6.6.7.21 (Flash) defines the bit field decoded into
     * fired state, return status, mode, flash-function flag, and red-eye mode.
     */
    public function flashInfo(): ?FlashInfo
    {
        return $this->converters->flashFromShort($this->flash());
    }

    /**
     * Returns the flash energy in beam candle power seconds when available.
     *
     * EXIF 3.0 §4.6.6.7.24 (FlashEnergy)
     *
     * @return float|null
     */
    public function flashEnergy(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FLASH_ENERGY);
    }

    // ========================================================================
    // Metering
    // ========================================================================

    /**
     * Returns the metering mode enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.19 (MeteringMode) catalogue of camera metering algorithms.
     *
     * @return MeteringMode|null
     */
    public function meteringMode(): ?MeteringMode
    {
        // EXIF 3.0 §4.6.6.7.19: default is 0 (Unknown).
        $rawMeteringMode = $this->reader->enumValue($this->exifIfd, ExifTag::METERING_MODE) ?? 0;

        return MeteringMode::fromExifValue($rawMeteringMode);
    }

    // ========================================================================
    // Scene / capture settings
    // ========================================================================

    /**
     * Returns the scene capture type enum when recorded.
     *
     * EXIF 3.0 §4.6.6.7.40 (SceneCaptureType)
     *
     * @return SceneCaptureType|null
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        // EXIF 3.0 §4.6.6.7.40: default is 0 (Standard).
        $rawSceneCaptureType = $this->reader->enumValue($this->exifIfd, ExifTag::SCENE_CAPTURE_TYPE) ?? 0;

        return SceneCaptureType::fromExifValue($rawSceneCaptureType);
    }

    /**
     * Returns the scene type classification when present.
     *
     * EXIF 3.0 §4.6.6.7.33 (SceneType)
     */
    public function sceneType(): ?SceneType
    {
        $value = $this->reader->value($this->exifIfd, ExifTag::SCENE_TYPE);

        if (is_int($value)) {
            return SceneType::fromExifValue($value);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (is_int($first)) {
                return SceneType::fromExifValue($first);
            }

            return null;
        }

        if (is_string($value) && $value !== '') {
            return SceneType::fromExifValue(ord($value[0]));
        }

        // EXIF 3.0 §4.6.6.7.33: default is 1 (directly photographed image).
        return SceneType::fromExifValue(1);
    }

    /**
     * Returns whether a custom rendering process was applied.
     *
     * EXIF 3.0 §4.6.6.7.35 (CustomRendered)
     */
    public function customRendered(): ?CustomRendered
    {
        // EXIF 3.0 §4.6.6.7.35: default is 0 (Normal process).
        $value = $this->reader->int($this->exifIfd, ExifTag::CUSTOM_RENDERED) ?? 0;

        return CustomRendered::fromExifValue($value);
    }

    /**
     * Returns the in-camera contrast setting.
     *
     * EXIF 3.0 §4.6.6.7.42
     */
    public function contrast(): ?Contrast
    {
        // EXIF 3.0 §4.6.6.7.42: default is 0 (Normal).
        $value = $this->reader->int($this->exifIfd, ExifTag::CONTRAST) ?? 0;

        return Contrast::fromExifValue($value);
    }

    /**
     * Returns the in-camera saturation setting.
     *
     * EXIF 3.0 §4.6.6.7.43
     */
    public function saturation(): ?Saturation
    {
        // EXIF 3.0 §4.6.6.7.43: default is 0 (Normal).
        $value = $this->reader->int($this->exifIfd, ExifTag::SATURATION) ?? 0;

        return Saturation::fromExifValue($value);
    }

    /**
     * Returns the in-camera sharpness setting.
     *
     * EXIF 3.0 §4.6.6.7.44
     */
    public function sharpness(): ?Sharpness
    {
        // EXIF 3.0 §4.6.6.7.44: default is 0 (Normal).
        $value = $this->reader->int($this->exifIfd, ExifTag::SHARPNESS) ?? 0;

        return Sharpness::fromExifValue($value);
    }

    /**
     * Returns the gain control enum describing in-camera amplification.
     *
     * EXIF 3.0 §4.6.6.7.41
     */
    public function gainControl(): ?GainControl
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::GAIN_CONTROL);

        return GainControl::fromExifValue($value);
    }

    /**
     * Returns the white balance enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.37 (WhiteBalance)
     */
    public function whiteBalance(): ?WhiteBalance
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::WHITE_BALANCE);

        return WhiteBalance::fromExifValue($value);
    }

    /**
     * Returns the light source enum describing the scene illumination.
     *
     * EXIF 3.0 §4.6.6.7.20 (LightSource) mapping of coded illuminants and
     * default value 0 for unknown light sources.
     *
     * @return LightSource|null
     */
    public function lightSource(): ?LightSource
    {
        // EXIF 3.0 §4.6.6.7.20: default is 0 (Unknown).
        $rawLightSource = $this->reader->enumValue($this->exifIfd, ExifTag::LIGHT_SOURCE) ?? 0;

        return LightSource::fromExifValue($rawLightSource);
    }

    // ========================================================================
    // Subject distance / area
    // ========================================================================

    /**
     * Returns the subject distance in metres when provided.
     *
     * EXIF 3.0 §4.6.6.7.18 (SubjectDistance) states that a numerator of
     * 0xFFFFFFFF indicates infinity, while a numerator of 0 indicates an
     * unknown distance.
     */
    public function subjectDistance(): ?float
    {
        $value = $this->reader->normalisedValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE);

        if ($value === null) {
            return null;
        }

        $numerator = $this->subjectDistanceNumerator($value);

        if ($numerator === 0) {
            return null;
        }

        if ($numerator === 0xFFFFFFFF || $numerator === -1) {
            return INF;
        }

        return $this->converters->rationalToFloat($value);
    }

    /**
     * Returns the subject distance range enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.46 provides the four valid SubjectDistanceRange codes;
     * other values are reserved.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE_RANGE);

        return SubjectDistanceRange::fromExifValue($value);
    }

    /**
     * Returns the EXIF subject area as a structured value object.
     *
     * EXIF 3.0 §4.6.6.7.22: SubjectArea tag 0x9214 indicates the location and area of the main
     * subject in the overall scene.
     */
    public function subjectArea(): ?SubjectArea
    {
        $value = $this->reader->normalisedValue($this->exifIfd, ExifTag::SUBJECT_AREA);

        if ($value instanceof ExifNumericList) {
            /** @var list<int> $components */
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $components[] = $component->toInt('SubjectArea');
                } else {
                    $components[] = (int) $component;
                }
            }

            return SubjectArea::fromComponents($components);
        }

        return null;
    }

    /**
     * Returns the subject location coordinates when supplied.
     *
     * EXIF 3.0 §4.6.6.7.29 stores the unrotated centre pixel of the main
     * subject as (X, Y) relative to the upper-left corner. The tag always
     * contains exactly two SHORT values.
     *
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        $coordinates = $this->reader->numericList($this->exifIfd, ExifTag::SUBJECT_LOCATION);

        if ($coordinates === null || count($coordinates) !== 2) {
            return null;
        }

        return [
            0 => $coordinates[0],
            1 => $coordinates[1],
        ];
    }

    // ========================================================================
    // Focal length / focal plane
    // ========================================================================

    /**
     * Returns the focal length in millimetres if available.
     *
     * EXIF 3.0 §4.6.6.7.23 (FocalLength)
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_LENGTH);
    }

    /**
     * Returns the focal length in 35mm equivalent if available.
     *
     * @return int|null
     */
    public function focalLength35Mm(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::FOCAL_LENGTH_IN_35MM_FILM);
    }

    /**
     * Returns the focal plane X resolution.
     *
     * EXIF 3.0 §4.6.6.7.26 defines this as the number of pixels in the image
     * width per {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera
     * focal plane. The value refers to the primary image rather than the
     * physical sensor grid.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_PLANE_X_RESOLUTION);
    }

    /**
     * Returns the focal plane Y resolution.
     *
     * EXIF 3.0 §4.6.6.7.27 records the number of pixels in the image height per
     * {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera focal plane,
     * aligned with the primary image output.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_PLANE_Y_RESOLUTION);
    }

    /**
     * Returns the focal plane resolution unit.
     *
     * EXIF 3.0 §4.6.6.7.28 reuses the {@see ResolutionUnit} scale for focal
     * plane resolution values.
     */
    public function focalPlaneResolutionUnit(): int
    {
        // EXIF 3.0 §4.6.6.7.28: default is 2 (inches).
        return $this->reader->int($this->exifIfd, ExifTag::FOCAL_PLANE_RESOLUTION_UNIT) ?? 2;
    }

    // ========================================================================
    // CFA pattern
    // ========================================================================

    /**
     * Returns the CFA pattern layout when available.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the payload as two SHORT repeat units followed by m×n
     * component identifiers describing the colour filter array.
     */
    public function cfaPattern(): ?CfaPattern
    {
        $components = $this->reader->numericList($this->exifIfd, ExifTag::CFA_PATTERN);
        if ($components === null || count($components) < 3) {
            return null;
        }

        $horizontalRepeatPixelUnit = $components[0];
        $verticalRepeatPixelUnit   = $components[1];
        $patternValues             = array_slice($components, 2);

        return CfaPattern::fromComponents($horizontalRepeatPixelUnit, $verticalRepeatPixelUnit, $patternValues);
    }

    /**
     * Returns the CFA pattern as colour enums when possible.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        $pattern = $this->cfaPattern();

        return $pattern?->colors;
    }

    // ========================================================================
    // Composite image
    // ========================================================================

    /**
     * Returns the composite image classification when available.
     *
     * EXIF 3.0 §4.6.6.7.47 defines the CompositeImage tag with four enumerated
     * states, reserving all others.
     */
    public function compositeImage(): ?CompositeImage
    {
        $value = $this->reader->int($this->exifIfd, ExifTag::COMPOSITE_IMAGE);

        return $value !== null ? CompositeImage::fromExifValue($value) : null;
    }

    /**
     * Returns the number of source images contributing to the composite result.
     *
     * EXIF 3.0 §4.6.6.7.48 records both the total number of captured source images
     * and how many were actually used to assemble the
     * composite. Figure 24 requires two SHORT values where both counters are at
     * least two and the used count cannot exceed the captured total.
     *
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        $values = $this->reader->numericList($this->exifIfd, ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE);

        if (($values === null) || (count($values) !== 2)) {
            return null;
        }

        [$capturedCount, $usedCount] = $values;

        if (($capturedCount < 2) || ($usedCount < 2)) {
            return null;
        }

        if ($usedCount > $capturedCount) {
            return null;
        }

        return [$capturedCount, $usedCount];
    }

    /**
     * Decodes the SourceExposureTimesOfCompositeImage payload.
     *
     * EXIF 3.0 §4.6.6.7.49 Figure 25 stores eight summary RATIONAL values
     * followed by one or more sequences of SHORT counts and RATIONAL exposure
     * times representing the contributing source images.
     */
    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        $payload = $this->reader->rawString($this->exifIfd, ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);

        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->decodeSourceExposureTimes($payload);
    }

    // ========================================================================
    // OECF / spatial frequency response
    // ========================================================================

    /**
     * Returns the opto-electronic conversion function data.
     *
     * EXIF 3.0 §4.6.6.7.6 (Figure 16, Table 11) describes the relationship between
     * the camera's optical input and the image file values.
     */
    public function oecf(): ?Oecf
    {
        $payload = $this->oecfPayload();
        if ($payload === null) {
            return null;
        }

        $matrix = $this->converters->decodeOecf($payload, $this->byteOrder);

        return Oecf::fromMatrix($matrix);
    }

    /**
     * Returns the raw opto-electronic conversion function payload.
     */
    public function oecfPayload(): ?string
    {
        return $this->reader->rawString($this->exifIfd, ExifTag::OECF);
    }

    /**
     * Returns the decoded spatial frequency response table.
     *
     * EXIF 3.0 §4.6.3 Table 16: SFR records camera and optical system's spatial frequency
     * response characteristics.
     */
    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        $payload = $this->reader->rawString($this->exifIfd, ExifTag::SPATIAL_FREQUENCY_RESPONSE);
        $matrix  = $this->converters->decodeSpatialFrequencyResponse($payload, $this->byteOrder);

        return SpatialFrequencyResponse::fromMatrix($matrix);
    }

    // ========================================================================
    // File source / gamma / interoperability
    // ========================================================================

    /**
     * Returns the EXIF file source enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.32 (FileSource)
     */
    public function fileSource(): ?FileSource
    {
        foreach ([$this->exifIfd, $this->ifd0] as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            $value = $this->reader->value($ifd, ExifTag::FILE_SOURCE);

            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;

                if (is_int($first) || is_float($first)) {
                    return FileSource::fromExifValue((int) $first);
                }

                continue;
            }

            if (is_int($value) || is_float($value)) {
                return FileSource::fromExifValue((int) $value);
            }

            if (is_string($value) && $value !== '') {
                return FileSource::fromExifValue(ord($value[0]));
            }
        }

        // EXIF 3.0 §4.6.6.7.32: default is 3 (DSC).
        return FileSource::fromExifValue(3);
    }

    /**
     * Returns the gamma correction value when provided.
     *
     * EXIF 3.0 §4.6.6.2.2 (Gamma)
     */
    public function gamma(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::GAMMA);
    }

    /**
     * Returns the interoperability index string when recorded.
     *
     * EXIF 3.0 §4.6.8.1.1: ASCII[4] including terminating NUL.
     */
    public function interopIndex(): ?string
    {
        $entry = $this->interopIfd?->get(ExifTag::INTEROPERABILITY_INDEX);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII || $entry->count !== 4) {
            return null;
        }

        return $this->reader->str($this->interopIfd, ExifTag::INTEROPERABILITY_INDEX);
    }

    // ========================================================================
    // Alias methods
    // ========================================================================

    /**
     * Returns the ISO latitude yyy value when available.
     */
    public function isoLatitudeYyy(): ?int
    {
        return $this->isoSpeedLatitudeYyy();
    }

    /**
     * Returns the ISO latitude zzz value when available.
     */
    public function isoLatitudeZzz(): ?int
    {
        return $this->isoSpeedLatitudeZzz();
    }

    /**
     * Returns the shutter speed APEX value.
     */
    public function shutterSpeedEv(): ?float
    {
        return $this->shutterSpeedValue();
    }

    /**
     * Returns the aperture APEX value.
     */
    public function apertureEv(): ?float
    {
        return $this->apertureValue();
    }

    /**
     * Alias for iso() using exact EXIF tag name.
     * EXIF 3.0 §4.6.6.7.5 (PhotographicSensitivity).
     *
     * @return int|null ISO sensitivity value
     */
    public function photographicSensitivity(): ?int
    {
        return $this->iso();
    }

    /**
     * Alias for isoSpeedValue() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 -- Tag 0x8833 ISOSpeed.
     *
     * @return int|null ISO speed value
     */
    public function iSOSpeed(): ?int
    {
        return $this->isoSpeedValue();
    }

    /**
     * Alias for focalLength35Mm() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 -- Tag 0xA405 FocalLengthIn35mmFilm.
     *
     * @return int|null Focal length in 35mm equivalent
     */
    public function focalLengthIn35mmFilm(): ?int
    {
        return $this->focalLength35Mm();
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Maps the EXIF sensitivity type enumeration to ISO-related tag priorities.
     *
     * @return list<int>
     */
    private function sensitivityTagPriority(SensitivityType $type): array
    {
        return match ($type) {
            SensitivityType::STANDARD_OUTPUT_SENSITIVITY => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ],
            SensitivityType::RECOMMENDED_EXPOSURE_INDEX => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::ISO_SPEED => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SOS_AND_REI => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::SOS_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::REI_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SOS_AND_REI_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::UNKNOWN => [],
        };
    }

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

    /**
     * Extracts the numerator component from a subject distance value.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value.
     *
     * @return int|null Numerator value or null.
     */
    private function subjectDistanceNumerator(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?int {
        if ($value instanceof ExifRational) {
            return $value->numerator;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof ExifRational) {
                return $first->numerator;
            }

            return null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (is_int($first) || is_float($first)) {
                return (int) $first;
            }

            if ($first instanceof UInt64) {
                return $first->toInt('SubjectDistance numerator');
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Parses the binary layout defined for SourceExposureTimesOfCompositeImage.
     *
     * @param string $payload Raw tag payload stored as an UNDEFINED value.
     */
    private function decodeSourceExposureTimes(string $payload): ?SourceExposureTimes
    {
        $payloadLength = strlen($payload);
        $offset        = 0;

        $summary = [];
        for ($i = 0; $i < 8; ++$i) {
            if (($offset + IfdValueReader::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                return null;
            }

            $summaryValue = $this->decodeRationalFromBytes(substr($payload, $offset, IfdValueReader::RATIONAL_BYTE_LENGTH));
            if ($summaryValue === null) {
                return null;
            }

            $summary[] = $summaryValue;
            $offset += IfdValueReader::RATIONAL_BYTE_LENGTH;
        }

        $sequenceCount = $this->decodeShort($payload, $offset);
        if ($sequenceCount === null) {
            return null;
        }

        $offset += IfdValueReader::SHORT_BYTE_LENGTH;

        $sequences = [];

        for ($i = 0; $i < $sequenceCount; ++$i) {
            $imageCount = $this->decodeShort($payload, $offset);
            if ($imageCount === null) {
                return null;
            }

            $offset += IfdValueReader::SHORT_BYTE_LENGTH;

            $sequence = [];
            for ($image = 0; $image < $imageCount; ++$image) {
                if (($offset + IfdValueReader::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                    return null;
                }

                $value = $this->decodeRationalFromBytes(substr($payload, $offset, IfdValueReader::RATIONAL_BYTE_LENGTH));
                if ($value === null) {
                    return null;
                }

                $offset += IfdValueReader::RATIONAL_BYTE_LENGTH;
                $sequence[] = $value;
            }

            $sequences[] = $sequence;
        }

        if ($offset !== $payloadLength) {
            return null;
        }

        return new SourceExposureTimes(
            totalExposurePeriod: $summary[0],
            usedExposureTimeSum: $summary[1],
            allExposureTimeSum: $summary[2],
            sourceImageCount: $summary[3],
            maxUsedExposureTime: $summary[4],
            minUsedExposureTime: $summary[5],
            longestSourceExposureTime: $summary[6],
            shortestSourceExposureTime: $summary[7],
            sequences: $sequences,
        );
    }

    /**
     * Reads a SHORT value from a composite exposure payload.
     *
     * @param string $payload Raw payload bytes.
     * @param int    $offset  Offset within the payload.
     *
     * @return int|null Decoded value or null when out of range.
     */
    private function decodeShort(string $payload, int $offset): ?int
    {
        if (($offset + IfdValueReader::SHORT_BYTE_LENGTH) > strlen($payload)) {
            return null;
        }

        $format = $this->byteOrder === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, substr($payload, $offset, IfdValueReader::SHORT_BYTE_LENGTH), 'EXIF composite exposure short');
    }

    /**
     * Decodes a RATIONAL value from an 8-byte payload.
     *
     * @param string $bytes Raw 8-byte rational value.
     *
     * @return float|null Decoded float value or null when invalid.
     */
    private function decodeRationalFromBytes(string $bytes): ?float
    {
        if (strlen($bytes) !== IfdValueReader::RATIONAL_BYTE_LENGTH) {
            return null;
        }

        // RATIONAL values are stored as numerator/denominator pairs.
        $format    = $this->byteOrder === Endian::Little ? 'V' : 'N';
        $numerator = Unpack::int($format, substr($bytes, 0, 4), 'EXIF composite exposure numerator');
        $denom     = Unpack::int($format, substr($bytes, 4, 4), 'EXIF composite exposure denominator');

        if ($denom === 0) {
            return null;
        }

        return $numerator / $denom;
    }
}
