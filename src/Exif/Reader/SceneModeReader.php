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
use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\SubjectArea;

use function count;
use function is_float;
use function is_int;
use function is_string;
use function ord;

/**
 * Reads flash, metering, scene/capture settings and subject distance/area
 * metadata from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7 defines the picture-taking condition tags decoded by this reader.
 */
final readonly class SceneModeReader
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
     * Returns the flash status flags if present.
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
     */
    public function flashEnergy(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FLASH_ENERGY);
    }

    /**
     * Returns the metering mode enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.19 (MeteringMode) catalogue of camera metering algorithms.
     */
    public function meteringMode(): ?MeteringMode
    {
        // EXIF 3.0 §4.6.6.7.19: default is 0 (Unknown).
        $rawMeteringMode = $this->reader->enumValue($this->exifIfd, ExifTag::METERING_MODE) ?? 0;

        return MeteringMode::fromExifValue($rawMeteringMode);
    }

    /**
     * Returns the scene capture type enum when recorded.
     *
     * EXIF 3.0 §4.6.6.7.40 (SceneCaptureType)
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

        if (is_string($value) && ($value !== '')) {
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
     */
    public function lightSource(): ?LightSource
    {
        // EXIF 3.0 §4.6.6.7.20: default is 0 (Unknown).
        $rawLightSource = $this->reader->enumValue($this->exifIfd, ExifTag::LIGHT_SOURCE) ?? 0;

        return LightSource::fromExifValue($rawLightSource);
    }

    /**
     * Returns the subject distance in metres when provided.
     *
     * EXIF 3.0 §4.6.6.7.18 (SubjectDistance) states that a numerator of
     * 0xFFFFFFFF indicates infinity, while a numerator of 0 indicates an
     * unknown distance.
     */
    public function subjectDistance(): ?float
    {
        $value = $this->reader->normalizedValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE);

        if ($value === null) {
            return null;
        }

        $numerator = $this->subjectDistanceNumerator($value);

        if ($numerator === 0) {
            return null;
        }

        if ($numerator === ExifConst::EXIF_UNKNOWN_DENOMINATOR || $numerator === -1) {
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
        $value = $this->reader->normalizedValue($this->exifIfd, ExifTag::SUBJECT_AREA);

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
}
