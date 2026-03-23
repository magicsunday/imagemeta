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
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Converts TIFF/EXIF RATIONAL and SRATIONAL values to PHP floats.
 *
 * TIFF 6.0 §2.2 defines RATIONAL/SRATIONAL arithmetic generically as
 * numerator/denominator division.
 */
final readonly class RationalConverter
{
    /**
     * Creates the converter with its numeric dependency.
     *
     * @param NumericConverter $numericConverter Dependency for numeric conversions.
     */
    public function __construct(
        private NumericConverter $numericConverter,
    ) {
    }

    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * EXIF 3.0 §4.6 reiterates that RATIONAL and SRATIONAL values are stored as
     * numerator/denominator pairs. Unknown sentinels are tag-specific EXIF semantics
     * and are intentionally not handled by this generic converter.
     *
     * @param int|float|string|array<int, int|float|string|array<int, int|float|string>|UInt64|ExifRational>|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The value to convert.
     */
    public function toFloat(
        int|float|string|array|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UInt64) {
            return (float) $value->toInt('RATIONAL conversion');
        }

        if ($value instanceof ExifRational) {
            return $value->denominator !== 0
                ? $value->numerator / $value->denominator
                : null;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $first instanceof ExifRational
                ? $this->toFloat($first)
                : null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return is_int($first) || is_float($first)
                ? (float) $first
                : null;
        }

        if (is_array($value)) {
            $components = array_values($value);
            $first      = $components[0] ?? null;

            // Handle array of ExifRationals
            if ($first instanceof ExifRational) {
                return $this->toFloat($first);
            }

            // Handle nested arrays like [[1,2], [3,4]]
            if (is_array($first)) {
                $num = $first[0] ?? null;
                $den = $first[1] ?? null;

                if (($num === null) || ($den === null)) {
                    return null;
                }

                $numVal = $this->numericConverter->normalizeComponent($num);
                $denVal = $this->numericConverter->normalizeComponent($den);

                if (($numVal === null) || ($denVal === null) || ($denVal === 0.0)) {
                    return null;
                }

                return $numVal / $denVal;
            }

            // Handle direct rational pairs like [1, 2] representing numerator/denominator
            if (isset($components[1])) {
                $numComponent = $components[0];
                $denComponent = $components[1];

                // Only handle scalar types for direct rational pairs
                if ((is_int($numComponent) || is_float($numComponent) || is_string($numComponent)) && (is_int($denComponent) || is_float($denComponent) || is_string($denComponent))) {
                    $numVal = $this->numericConverter->normalizeComponent($numComponent);
                    $denVal = $this->numericConverter->normalizeComponent($denComponent);

                    if (($numVal === null) || ($denVal === null) || ($denVal === 0.0)) {
                        return null;
                    }

                    return $numVal / $denVal;
                }
            }

            // Single element array - return as float
            if (is_int($first) || is_float($first)) {
                return (float) $first;
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        // string type
        $trimmed = trim($value);

        return is_numeric($trimmed) ? (float) $trimmed : null;
    }

    /**
     * Converts an SRATIONAL triplet to a float vector [X, Y, Z].
     *
     * Used for acceleration vectors and similar 3-component values.
     *
     * @param ExifRationalList $value List containing exactly 3 SRATIONAL values.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function tripletToFloatVector(ExifRationalList $value): ?array
    {
        if (count($value->values) !== 3) {
            return null;
        }

        $result = [];

        foreach ($value->values as $rational) {
            $floatVal = $this->toFloat($rational);

            if ($floatVal === null) {
                return null;
            }

            $result[] = $floatVal;
        }

        /** @var array{0: float, 1: float, 2: float} $result */
        return $result;
    }

    /**
     * Tests whether the denominator is invalid for generic rational conversion.
     *
     * @param int|float $denominator The denominator to check.
     *
     * @return bool True if the denominator is zero.
     */
    public function isUnknownDenominator(int|float $denominator): bool
    {
        return ($denominator === 0) || ($denominator === 0.0);
    }
}
