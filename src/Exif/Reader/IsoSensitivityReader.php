<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;

/**
 * Reads ISO sensitivity and photographic sensitivity metadata from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7.5–§4.6.6.7.12 defines the sensitivity-related tags
 * decoded by this reader.
 */
final readonly class IsoSensitivityReader
{
    /**
     * @param IfdValueReader $reader       Value reader for IFD tag extraction.
     * @param Ifd            $ifd0         Root IFD of the TIFF structure.
     * @param Ifd|null       $exifIfd      Sub IFD containing EXIF-specific tags.
     * @param FallbackIfdSet $fallbackIfds Fallback IFD resolution set.
     */
    public function __construct(
        private IfdValueReader $reader,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
        private FallbackIfdSet $fallbackIfds,
    ) {
    }

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

    // ── Alias methods ───────────────────────────────────────────

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

    // ── Private helpers ─────────────────────────────────────────

    /**
     * Maps the EXIF sensitivity type enumeration to ISO-related tag priorities.
     *
     * @return list<int>
     */
    private function sensitivityTagPriority(SensitivityType $type): array
    {
        return match ($type) {
            SensitivityType::StandardOutputSensitivity => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ],
            SensitivityType::RecommendedExposureIndex => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::IsoSpeed => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SosAndRei => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::SosAndIso => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::ReiAndIso => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SosAndReiAndIso => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::Unknown => [],
        };
    }
}
