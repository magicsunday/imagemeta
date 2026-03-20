<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory\Structured;

use MagicSunday\ImageMeta\Value\Region;

use function abs;
use function ceil;
use function log10;

/**
 * Normalizes region geometry and confidence values to the unit interval.
 */
final readonly class RegionCoordinateNormalizer
{
    /**
     * Creates a normalized bounding box from center and dimensions.
     *
     * @param float                          $centerX    Center X coordinate.
     * @param float                          $centerY    Center Y coordinate.
     * @param float                          $width      Box width.
     * @param float                          $height     Box height.
     * @param array{w: float, h: float}|null $dimensions Image dimensions for normalization.
     *
     * @return array{x: float, y: float, w: float, h: float}|null Normalized bounding box or null if invalid.
     */
    public function normalizedBox(float $centerX, float $centerY, float $width, float $height, ?array $dimensions): ?array
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $scaledCenterX = $centerX;
        $scaledCenterY = $centerY;
        $scaledWidth   = $width;
        $scaledHeight  = $height;

        if ($dimensions !== null) {
            if ($scaledCenterX > 1.0 || $scaledWidth > 1.0) {
                $scaledCenterX /= $dimensions['w'];
                $scaledWidth /= $dimensions['w'];
            }

            if ($scaledCenterY > 1.0 || $scaledHeight > 1.0) {
                $scaledCenterY /= $dimensions['h'];
                $scaledHeight /= $dimensions['h'];
            }
        }

        if (($scaledCenterX > 1.0 || $scaledCenterY > 1.0 || $scaledWidth > 1.0 || $scaledHeight > 1.0) && ($scaledCenterX <= 100.0 && $scaledCenterY <= 100.0 && $scaledWidth <= 100.0 && $scaledHeight <= 100.0)) {
            $scaledCenterX /= 100.0;
            $scaledCenterY /= 100.0;
            $scaledWidth /= 100.0;
            $scaledHeight /= 100.0;
        }

        $halfWidth  = $scaledWidth / 2.0;
        $halfHeight = $scaledHeight / 2.0;

        return [
            'x' => $this->clamp($scaledCenterX - $halfWidth),
            'y' => $this->clamp($scaledCenterY - $halfHeight),
            'w' => $this->clamp($scaledWidth),
            'h' => $this->clamp($scaledHeight),
        ];
    }

    /**
     * Constrains a normalized coordinate to the unit interval.
     *
     * @param float $value Coordinate or dimension value to clamp.
     *
     * @return float Value restricted to the range [0.0, 1.0].
     */
    public function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * Calculates the center point of a region.
     *
     * @param Region $region Region to calculate center for.
     *
     * @return array{0: float, 1: float} Center coordinates [x, y].
     */
    public function regionCenter(Region $region): array
    {
        return [
            $region->x + ($region->w / 2.0),
            $region->y + ($region->h / 2.0),
        ];
    }

    /**
     * Normalizes Apple-specific confidence values to the unit interval.
     */
    public function normalizedConfidence(?float $confidence, float $scale): ?float
    {
        if ($confidence === null) {
            return null;
        }

        if ($scale <= 1.0 || abs($confidence) <= 1.0) {
            return $confidence;
        }

        $normalized = $confidence / $scale;

        if ($normalized > 1.0) {
            return 1.0;
        }

        if ($normalized < -1.0) {
            return -1.0;
        }

        return $normalized;
    }

    /**
     * Calculates a normalized confidence scale from confidence levels.
     *
     * @param list<float|null> $confidenceLevels Raw confidence level values.
     * @param list<float|null> $confidences      Confidence percentage values.
     *
     * @return float Normalized confidence scale value.
     */
    public function confidenceScale(array $confidenceLevels, array $confidences): float
    {
        $maxConfidence = 0.0;

        foreach ([$confidenceLevels, $confidences] as $values) {
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }

                $absolute = abs($value);

                if ($absolute > $maxConfidence) {
                    $maxConfidence = $absolute;
                }
            }
        }

        if ($maxConfidence <= 1.0) {
            return 1.0;
        }

        $scale = 10.0 ** ceil(log10($maxConfidence));

        if ($scale <= 0.0) {
            return 1.0;
        }

        return $scale;
    }
}
