<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use Closure;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function fmod;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ord;
use function strlen;

/**
 * Converts EXIF numeric values to PHP scalars.
 *
 * Handles various numeric representations found in EXIF metadata including
 * UInt64 values from BigTIFF.
 */
final readonly class NumericConverter
{
    /**
     * Creates the converter with an optional rational-to-float callback.
     *
     * The callback breaks the circular dependency between NumericConverter
     * and RationalConverter by deferring the rational conversion to a closure.
     *
     * @param (Closure(int|float|string|array<int, int|float|string|array<int, int|float|string>|UInt64|ExifRational>|ExifRational|ExifRationalList|ExifNumericList|UInt64|null): ?float)|null $rationalToFloat Callback for rational-to-float conversions.
     */
    public function __construct(
        private ?Closure $rationalToFloat = null,
    ) {
    }

    /**
     * Normalises a numeric component from a rational pair.
     */
    public function normaliseComponent(int|float|string|UInt64 $component): ?float
    {
        if ($component instanceof UInt64) {
            if (!$component->fitsSignedInt()) {
                return null;
            }

            return (float) $component->toInt('EXIF rational component');
        }

        if (is_int($component) || is_float($component)) {
            return (float) $component;
        }

        if (!is_numeric($component)) {
            return null;
        }

        return (float) $component;
    }

    /**
     * Converts an unsigned 64-bit value into a signed integer when possible.
     *
     * BigTIFF uses LONG8 (64-bit) types for offset and count fields.
     */
    public function uint64ToInt(UInt64 $value, string $context): ?int
    {
        if (!$value->fitsSignedInt()) {
            return null;
        }

        return $value->toInt($context);
    }

    /**
     * Normalises numeric EXIF representations into a list of integers.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     *
     * @return list<int>|null
     */
    public function toIntList(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        if ($value instanceof ExifNumericList) {
            if ($value->values === []) {
                return null;
            }

            $ints = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $intComponent = $this->uint64ToInt($component, 'EXIF numeric component');
                    if ($intComponent === null) {
                        return null;
                    }

                    $ints[] = $intComponent;
                    continue;
                }

                $ints[] = (int) $component;
            }

            return $ints;
        }

        if ($value instanceof ExifRationalList) {
            if ($value->values === []) {
                return null;
            }

            $ints = [];
            foreach ($value->values as $component) {
                $numeric = $this->rationalToFloat instanceof Closure ? ($this->rationalToFloat)($component) : null;
                if ($numeric === null || fmod($numeric, 1.0) !== 0.0) {
                    return null;
                }

                $ints[] = (int) $numeric;
            }

            return $ints;
        }

        if ($value instanceof ExifRational) {
            $numeric = $this->rationalToFloat instanceof Closure ? ($this->rationalToFloat)($value) : null;
            if ($numeric === null || fmod($numeric, 1.0) !== 0.0) {
                return null;
            }

            return [(int) $numeric];
        }

        if ($value instanceof UInt64) {
            $intValue = $this->uint64ToInt($value, 'EXIF numeric value');
            if ($intValue === null) {
                return null;
            }

            return [$intValue];
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $ints = [];
            foreach ($value as $component) {
                if ($component instanceof UInt64) {
                    $intComponent = $this->uint64ToInt($component, 'EXIF numeric array component');
                    if ($intComponent === null) {
                        return null;
                    }

                    $ints[] = $intComponent;
                    continue;
                }

                if (!is_numeric($component)) {
                    return null;
                }

                $ints[] = (int) $component;
            }

            return $ints;
        }

        if (is_float($value)) {
            if (fmod($value, 1.0) !== 0.0) {
                return null;
            }

            return [(int) $value];
        }

        if (is_int($value)) {
            return [$value];
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $length = strlen($value);
        $ints   = [];
        for ($i = 0; $i < $length; ++$i) {
            $ints[] = ord($value[$i]);
        }

        return $ints;
    }
}
