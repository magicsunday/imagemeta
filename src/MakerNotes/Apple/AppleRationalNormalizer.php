<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use function array_is_list;
use function array_key_exists;
use function count;
use function explode;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_split;
use function str_contains;
use function trim;

/**
 * Stateless helper that normalizes rational and numeric values from Apple maker note dictionaries.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 */
final readonly class AppleRationalNormalizer
{
    /**
     * Normalizes a rational value to float representation.
     *
     * @param string|int|float|bool|array<int|string, NativePlistValue>|null $value Raw value to normalize.
     *
     * @return float|null Normalized float value or null if invalid.
     */
    public function normalizeRationalFloat(string|int|float|bool|array|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            if ($normalized === '') {
                return null;
            }

            if (str_contains($normalized, '/')) {
                [$numeratorRaw, $denominatorRaw] = explode('/', $normalized, 2);
                $numerator                       = trim($numeratorRaw);
                $denominator                     = trim($denominatorRaw);

                if ($numerator === '' || $denominator === '' || !is_numeric($numerator) || !is_numeric($denominator)) {
                    return null;
                }

                $denominatorFloat                = (float) $denominator;

                if ($denominatorFloat === 0.0) {
                    return null;
                }

                return (float) $numerator / $denominatorFloat;
            }

            $components = preg_split('/\s+/', $normalized);

            if (($components !== false) && (count($components) === 2)) {
                [$numerator, $denominator] = $components;

                if (($numerator !== '') && ($denominator !== '') && is_numeric($numerator) && is_numeric($denominator)) {
                    $denominatorFloat = (float) $denominator;

                    if ($denominatorFloat === 0.0) {
                        return null;
                    }

                    return (float) $numerator / $denominatorFloat;
                }
            }

            if (!is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['value', 'Value', 'data', 'Data', 'ratio', 'Ratio'] as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = $value[$key];
                $nested    = $this->normalizeRationalFloat($candidate);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        if (array_key_exists('values', $value) && is_array($value['values'])) {
            $candidate = $value['values'];
            $nested    = $this->normalizeRationalFloat($candidate);

            if ($nested !== null) {
                return $nested;
            }
        }

        $numerator   = $this->numericComponentFromArray($value, 'numerator', 'Numerator', 'num', 'Num', 'numer');
        $denominator = $this->numericComponentFromArray($value, 'denominator', 'Denominator', 'den', 'Den', 'denom', 'Denom');

        if (($numerator !== null) && ($denominator !== null)) {
            if ($denominator === 0.0) {
                return null;
            }

            return $numerator / $denominator;
        }

        if (!array_is_list($value)) {
            return null;
        }

        $count       = count($value);

        if ($count >= 2) {
            /** @var NativePlistValue $component */
            $component = $value[0];
            $num       = $this->numericScalarValue($component);

            /** @var NativePlistValue $component */
            $component = $value[1];
            $den       = $this->numericScalarValue($component);

            if (($num !== null) && ($den !== null) && ($den !== 0.0)) {
                return $num / $den;
            }
        }

        foreach ($value as $entry) {
            /** @var NativePlistValue $entryValue */
            $entryValue = $entry;
            $float      = $this->normalizeRationalFloat($entryValue);

            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * Extracts a numeric component from an array using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $value   Array containing numeric components.
     * @param string                              ...$keys Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $value
     *
     * @return float|null Numeric component value or null if not found.
     */
    public function numericComponentFromArray(array $value, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $value[$key];
            $numeric   = $this->numericScalarValue($candidate);

            if ($numeric !== null) {
                return $numeric;
            }
        }

        return null;
    }

    /**
     * Normalizes a scalar value to numeric representation.
     *
     * @param string|int|float|bool|array<int|string, NativePlistValue>|null $value Raw scalar value.
     *
     * @return float|null Numeric value or null if invalid.
     */
    public function numericScalarValue(string|int|float|bool|array|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            if ($normalized === '') {
                return null;
            }

            if (is_numeric($normalized)) {
                return (float) $normalized;
            }

            return $this->normalizeRationalFloat($normalized);
        }

        if (is_array($value)) {
            return $this->normalizeRationalFloat($value);
        }

        return null;
    }
}
