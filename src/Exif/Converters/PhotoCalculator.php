<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use function atan;
use function rad2deg;

/**
 * Photographic calculations for EXIF metadata interpretation.
 *
 * Provides calculations for exposure, depth of field, and field of view
 * based on camera parameters extracted from EXIF data.
 */
final readonly class PhotoCalculator
{
    private const float FULL_FRAME_WIDTH_MM               = 36.0;

    private const float FULL_FRAME_HEIGHT_MM              = 24.0;

    private const float FULL_FRAME_DIAGONAL_MM            = 43.2666153056;

    private const float FULL_FRAME_CIRCLE_OF_CONFUSION_MM = 0.030;

    /**
     * Calculates the hyperfocal distance in metres using the thin lens approximation.
     */
    public function calcHyperfocalM(?float $focalLengthMm, ?float $fNumber, ?float $circleOfConfusionMm): ?float
    {
        if ($focalLengthMm === null || $focalLengthMm <= 0.0 || $fNumber === null || $fNumber <= 0.0 || $circleOfConfusionMm === null || $circleOfConfusionMm <= 0.0) {
            return null;
        }

        $fSquared = $focalLengthMm * $focalLengthMm;
        $hMm      = $fSquared / ($fNumber * $circleOfConfusionMm) + $focalLengthMm;

        return $hMm / 1000.0;
    }

    /**
     * Calculates the crop factor from focal lengths.
     */
    public function calcCropFactor(?int $focalLength35mm, ?float $focalLengthMm): ?float
    {
        if ($focalLength35mm === null || $focalLength35mm <= 0 || $focalLengthMm === null || $focalLengthMm <= 0.0) {
            return null;
        }

        return (float) $focalLength35mm / $focalLengthMm;
    }

    /**
     * Calculates the circle of confusion in millimetres based on the crop factor.
     */
    public function calcCircleOfConfusionMm(?float $cropFactor): ?float
    {
        if ($cropFactor === null) {
            return self::FULL_FRAME_CIRCLE_OF_CONFUSION_MM;
        }

        if ($cropFactor <= 0.0) {
            return null;
        }

        return self::FULL_FRAME_CIRCLE_OF_CONFUSION_MM / $cropFactor;
    }

    /**
     * Approximates the diagonal field of view in degrees.
     *
     * The result reflects the diagonal angle of view of the recorded frame.
     */
    public function calcFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        $fov = $this->calcFovFromSensorDimension(
            self::FULL_FRAME_DIAGONAL_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );

        if ($fov !== null) {
            return $fov;
        }

        if (($cropFactor !== null) && ($cropFactor > 0.0)) {
            $equivalent = 50.0 * $cropFactor;

            return rad2deg(2.0 * atan(self::FULL_FRAME_DIAGONAL_MM / (2.0 * $equivalent)));
        }

        return null;
    }

    /**
     * Approximates the horizontal field of view in degrees.
     */
    public function calcHorizontalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return $this->calcFovFromSensorDimension(
            self::FULL_FRAME_WIDTH_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );
    }

    /**
     * Approximates the vertical field of view in degrees.
     */
    public function calcVerticalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return $this->calcFovFromSensorDimension(
            self::FULL_FRAME_HEIGHT_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );
    }

    /**
     * Calculates the field of view for the supplied sensor dimension.
     */
    private function calcFovFromSensorDimension(
        float $fullFrameDimensionMm,
        ?float $focalLengthMm,
        ?int $focalLength35mm,
        ?float $cropFactor,
    ): ?float {
        if (($focalLengthMm !== null) && ($focalLengthMm > 0.0) && ($cropFactor !== null) && ($cropFactor > 0.0)) {
            $sensorDimension = $fullFrameDimensionMm / $cropFactor;

            return rad2deg(2.0 * atan($sensorDimension / (2.0 * $focalLengthMm)));
        }

        if (($focalLength35mm !== null) && ($focalLength35mm > 0)) {
            return rad2deg(2.0 * atan($fullFrameDimensionMm / (2.0 * (float) $focalLength35mm)));
        }

        return null;
    }
}
