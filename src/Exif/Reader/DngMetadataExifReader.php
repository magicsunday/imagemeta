<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

/**
 * Reads DNG-specific metadata tags from IFD0.
 *
 * DNG 1.7.1.0 defines the colour calibration, white balance, linearisation,
 * and rendering tags decoded by this reader.
 */
final readonly class DngMetadataExifReader
{
    /**
     * @param IfdValueReader $reader Value reader for IFD tag extraction.
     * @param Ifd            $ifd0   Root IFD of the TIFF structure.
     */
    public function __construct(
        private IfdValueReader $reader,
        private Ifd $ifd0,
    ) {
    }

    /**
     * Returns the DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGVersion): BYTE[4], required in IFD0.
     */
    public function dngVersion(): ?string
    {
        return $this->reader->dngVersionTag($this->ifd0, DngTag::DNG_VERSION);
    }

    /**
     * Returns the backward-compatibility DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGBackwardVersion): BYTE[4], required in IFD0.
     */
    public function dngBackwardVersion(): ?string
    {
        return $this->reader->dngVersionTag($this->ifd0, DngTag::DNG_BACKWARD_VERSION);
    }

    /**
     * Returns the DNG profile name from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, ProfileName): ASCII or UTF-8.
     */
    public function dngProfileName(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::PROFILE_NAME);
    }

    /**
     * Returns the primary DNG calibration illuminant identifier.
     *
     * DNG 1.7.1.0 (DNG Tags, CalibrationIlluminant1): SHORT.
     */
    public function dngCalibrationIlluminant1(): ?int
    {
        return $this->reader->int($this->ifd0, DngTag::CALIBRATION_ILLUMINANT_1);
    }

    /**
     * Returns the secondary DNG calibration illuminant identifier.
     *
     * DNG 1.7.1.0 (DNG Tags, CalibrationIlluminant2): SHORT.
     */
    public function dngCalibrationIlluminant2(): ?int
    {
        return $this->reader->int($this->ifd0, DngTag::CALIBRATION_ILLUMINANT_2);
    }

    /**
     * Returns the primary DNG color matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ColorMatrix1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngColorMatrix1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::COLOR_MATRIX_1);
    }

    /**
     * Returns the secondary DNG color matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ColorMatrix2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngColorMatrix2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::COLOR_MATRIX_2);
    }

    /**
     * Returns the primary DNG camera calibration matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraCalibration1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngCameraCalibration1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::CAMERA_CALIBRATION_1);
    }

    /**
     * Returns the secondary DNG camera calibration matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraCalibration2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngCameraCalibration2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::CAMERA_CALIBRATION_2);
    }

    /**
     * Returns the primary DNG forward matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ForwardMatrix1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngForwardMatrix1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::FORWARD_MATRIX_1);
    }

    /**
     * Returns the secondary DNG forward matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ForwardMatrix2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngForwardMatrix2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::FORWARD_MATRIX_2);
    }

    /**
     * Returns the DNG as-shot neutral white balance coordinates.
     *
     * DNG 1.7.1.0 (DNG Tags, AsShotNeutral): RATIONAL or SHORT.
     *
     * @return list<float>|null
     */
    public function dngAsShotNeutral(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::AS_SHOT_NEUTRAL);
    }

    /**
     * Returns the DNG as-shot white point chromaticity coordinates.
     *
     * DNG 1.7.1.0 (DNG Tags, AsShotWhiteXY): RATIONAL.
     *
     * @return list<float>|null
     */
    public function dngAsShotWhiteXY(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::AS_SHOT_WHITE_XY);
    }

    /**
     * Returns the DNG baseline exposure offset value.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineExposure): SRATIONAL.
     */
    public function dngBaselineExposure(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_EXPOSURE);
    }

    /**
     * Returns the DNG baseline noise level estimate.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineNoise): RATIONAL.
     */
    public function dngBaselineNoise(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_NOISE);
    }

    /**
     * Returns the DNG baseline sharpness estimate.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineSharpness): RATIONAL.
     */
    public function dngBaselineSharpness(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_SHARPNESS);
    }

    /**
     * Returns the DNG linear response limit for the sensor.
     *
     * DNG 1.7.1.0 (DNG Tags, LinearResponseLimit): RATIONAL.
     */
    public function dngLinearResponseLimit(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::LINEAR_RESPONSE_LIMIT);
    }

    /**
     * Returns the DNG linearization lookup table.
     *
     * DNG 1.7.1.0 (DNG Tags, LinearizationTable): SHORT.
     *
     * @return list<int>|null
     */
    public function linearizationTable(): ?array
    {
        return $this->reader->numericList($this->ifd0, DngTag::LINEARIZATION_TABLE);
    }

    /**
     * Returns the DNG analog balance values per color channel.
     *
     * DNG 1.7.1.0 (DNG Tags, AnalogBalance): RATIONAL.
     *
     * @return list<float>|null
     */
    public function analogBalance(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::ANALOG_BALANCE);
    }

    /**
     * Returns the DNG anti-aliasing strength applied during capture.
     *
     * DNG 1.7.1.0 (DNG Tags, AntiAliasStrength): RATIONAL.
     */
    public function antiAliasStrength(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::ANTI_ALIAS_STRENGTH);
    }

    /**
     * Returns the DNG shadow scale parameter for tone mapping.
     *
     * DNG 1.7.1.0 (DNG Tags, ShadowScale): RATIONAL.
     */
    public function shadowScale(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::SHADOW_SCALE);
    }

    /**
     * Returns the DNG best quality scale factor for rendering.
     *
     * DNG 1.7.1.0 (DNG Tags, BestQualityScale): RATIONAL.
     */
    public function bestQualityScale(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BEST_QUALITY_SCALE);
    }

    /**
     * Returns the DNG baseline exposure offset adjustment.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineExposureOffset): SRATIONAL.
     */
    public function baselineExposureOffset(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_EXPOSURE_OFFSET);
    }

    /**
     * Returns the DNG profile tone curve data.
     *
     * DNG 1.7.1.0 (DNG Tags, ProfileToneCurve): FLOAT.
     *
     * @return list<float>|null
     */
    public function profileToneCurve(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::PROFILE_TONE_CURVE);
    }
}
