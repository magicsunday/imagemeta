<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Value\RunTime;

use function array_is_list;
use function array_key_exists;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function preg_split;
use function str_contains;
use function strcmp;
use function strlen;
use function substr;
use function trim;

/**
 * Stateless helper that extracts typed values from Apple maker note dictionaries.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class AppleDictionaryValueExtractor
{
    private AppleFlagExtractor $flagExtractor;

    public function __construct()
    {
        $this->flagExtractor = new AppleFlagExtractor();
    }

    /**
     * Extracts a RunTime value from dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary containing runtime data.
     * @param string                              $key        Key to look up.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return RunTime|null RunTime value object or null if not found.
     */
    public function runTimeValue(array $dictionary, string $key): ?RunTime
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $value = $dictionary[$key];
        if (!is_array($value)) {
            return null;
        }

        $epoch     = $this->intValue($value, 'epoch', 'Epoch');
        $timescale = $this->intValue($value, 'timescale', 'Timescale');
        $rawValue  = $this->intValue($value, 'value', 'Value');
        $flags     = $this->intValue($value, 'flags', 'Flags');

        if ($epoch === null && $timescale === null && $rawValue === null && $flags === null) {
            return null;
        }

        return new RunTime($epoch, $timescale, $rawValue, $flags);
    }

    /**
     * Extracts a boolean value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return bool|null Boolean value if found, null otherwise.
     */
    public function boolDictionaryValue(array $dictionary, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $dictionary[$key];
            $value     = $this->flagExtractor->boolValue($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extracts a rational float value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return float|null Rational float value if found, null otherwise.
     */
    public function rationalFloatValue(array $dictionary, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $dictionary[$key];
            $float     = $this->normaliseRationalFloat($candidate);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * Normalizes a rational value to float representation.
     *
     * @param string|int|float|bool|array<int|string, NativePlistValue>|null $value Raw value to normalize.
     *
     * @return float|null Normalized float value or null if invalid.
     */
    public function normaliseRationalFloat(string|int|float|bool|array|null $value): ?float
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

                $denominatorFloat = (float) $denominator;
                if ($denominatorFloat === 0.0) {
                    return null;
                }

                return (float) $numerator / $denominatorFloat;
            }

            $components = preg_split('/\s+/', $normalized);
            if ($components !== false && count($components) === 2) {
                [$numerator, $denominator] = $components;

                if ($numerator !== '' && $denominator !== '' && is_numeric($numerator) && is_numeric($denominator)) {
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
                $nested    = $this->normaliseRationalFloat($candidate);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        if (array_key_exists('values', $value) && is_array($value['values'])) {
            $candidate = $value['values'];
            $nested    = $this->normaliseRationalFloat($candidate);
            if ($nested !== null) {
                return $nested;
            }
        }

        $numerator   = $this->numericComponentFromArray($value, 'numerator', 'Numerator', 'num', 'Num', 'numer');
        $denominator = $this->numericComponentFromArray($value, 'denominator', 'Denominator', 'den', 'Den', 'denom', 'Denom');
        if ($numerator !== null && $denominator !== null) {
            if ($denominator === 0.0) {
                return null;
            }

            return $numerator / $denominator;
        }

        if (!array_is_list($value)) {
            return null;
        }

        $count = count($value);
        if ($count >= 2) {
            /** @var NativePlistValue $component */
            $component = $value[0];
            $num       = $this->numericScalarValue($component);

            /** @var NativePlistValue $component */
            $component = $value[1];
            $den       = $this->numericScalarValue($component);
            if ($num !== null && $den !== null && $den !== 0.0) {
                return $num / $den;
            }
        }

        foreach ($value as $entry) {
            /** @var NativePlistValue $entryValue */
            $entryValue = $entry;
            $float      = $this->normaliseRationalFloat($entryValue);
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

            return $this->normaliseRationalFloat($normalized);
        }

        if (is_array($value)) {
            return $this->normaliseRationalFloat($value);
        }

        return null;
    }

    /**
     * Extracts a string or integer value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return string|int|null String or integer value if found, null otherwise.
     */
    public function stringOrIntValue(array $dictionary, string ...$keys): string|int|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                $intValue = (int) $value;
                if ((float) $intValue === $value) {
                    return $intValue;
                }

                return (string) $value;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if ($this->isIntegerString($trimmed)) {
                    return $this->stringWithinIntRange($trimmed) ? (int) $trimmed : $trimmed;
                }

                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Extracts an identifier value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return string|int|null Identifier value if found, null otherwise.
     */
    public function identifierValue(array $dictionary, string ...$keys): string|int|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                $intValue = (int) $value;
                if ((float) $intValue === $value) {
                    return $intValue;
                }

                return (string) $value;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                return $trimmed !== '' ? $trimmed : null;
            }
        }

        return null;
    }

    /**
     * Checks whether a string contains a signed integer representation.
     *
     * @param string $value String to test.
     *
     * @return bool True when the string is an integer literal.
     */
    public function isIntegerString(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * Checks if a numeric string fits into the platform integer range.
     *
     * @param string $value Numeric string to validate.
     *
     * @return bool True when the value fits into a signed int.
     */
    public function stringWithinIntRange(string $value): bool
    {
        $negative = $value !== '' && $value[0] === '-';
        $digits   = $negative ? substr($value, 1) : $value;

        if ($digits === '') {
            return false;
        }

        $maxDigits = (string) PHP_INT_MAX;

        $digitLength = strlen($digits);
        $maxLength   = strlen($maxDigits);

        if ($digitLength < $maxLength) {
            return true;
        }

        if ($digitLength > $maxLength) {
            return false;
        }

        $comparison = strcmp($digits, $maxDigits);
        if ($comparison > 0) {
            return false;
        }

        if ($comparison < 0) {
            return true;
        }

        return !$negative;
    }

    /**
     * Extracts a string value from dictionary for a specific key.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              $key        Key to look up.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return string|null String value if found, null otherwise.
     */
    public function stringValue(array $dictionary, string $key): ?string
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $value = $dictionary[$key];
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Extracts a float value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return float|null Float value if found, null otherwise.
     */
    public function floatValue(array $dictionary, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_float($value)) {
                return $value;
            }

            if (is_int($value) || is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Extracts an integer value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return int|null Integer value if found, null otherwise.
     */
    public function intValue(array $dictionary, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Extracts a list of float values from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return list<float>|null List of float values if found, null otherwise.
     * @return list<float>|null
     */
    public function floatList(array $dictionary, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            $value = $dictionary[$key];
            if (is_float($value)) {
                return [$value];
            }

            if (is_int($value)) {
                return [(float) $value];
            }

            if (is_string($value) && is_numeric($value)) {
                return [(float) $value];
            }

            if (!is_array($value)) {
                continue;
            }

            if (!array_is_list($value) && array_key_exists('values', $value) && is_array($value['values'])) {
                $value = $value['values'];
            }

            if (!array_is_list($value)) {
                continue;
            }

            $result = [];
            foreach ($value as $entry) {
                if (is_float($entry)) {
                    $result[] = $entry;
                } elseif (is_int($entry) || is_numeric($entry)) {
                    $result[] = (float) $entry;
                }
            }

            if ($result !== []) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Extracts focus distance range from dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary containing focus distance data.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return list<float>|null Focus distance range [near, far] or null if not found.
     * @return list<float>|null
     */
    public function focusDistanceRangeValue(array $dictionary): ?array
    {
        $range = $this->floatList($dictionary, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $this->floatValue(
            $dictionary,
            'FocusDistanceRangeNear',
            'FocusDistanceRangeMin',
            'FocusDistanceNear',
        );
        $far = $this->floatValue(
            $dictionary,
            'FocusDistanceRangeFar',
            'FocusDistanceRangeMax',
            'FocusDistanceFar',
        );

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    /**
     * Extracts maker note version string from dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              $key        Key to look up.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return string|null Version string if found, null otherwise.
     */
    public function makerNoteVersionValue(array $dictionary, string $key): ?string
    {
        if (!array_key_exists($key, $dictionary)) {
            return null;
        }

        $scalar = $this->stringOrNumericValue($dictionary, $key);
        if ($scalar !== null) {
            return $scalar;
        }

        $value = $dictionary[$key];
        if (!is_array($value)) {
            return null;
        }

        if (!array_is_list($value) && array_key_exists('values', $value) && is_array($value['values'])) {
            $value = $value['values'];
        }

        if (!array_is_list($value)) {
            return null;
        }

        $components = [];
        foreach ($value as $entry) {
            if (is_int($entry)) {
                $components[] = (string) $entry;
                continue;
            }

            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed === '') {
                    continue;
                }

                if (!is_numeric($trimmed)) {
                    continue;
                }

                $components[] = (string) (int) $trimmed;
                continue;
            }

            if (is_float($entry)) {
                $components[] = (string) (int) $entry;
            }
        }

        if ($components === []) {
            return null;
        }

        return implode('.', $components);
    }

    /**
     * Extracts a string or numeric value from dictionary using prioritized keys.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return string|null String representation of value if found, null otherwise.
     */
    public function stringOrNumericValue(array $dictionary, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $dictionary[$key];
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }

            if (is_int($candidate) || is_float($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Extracts an enumerated string value from dictionary using a mapping table.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     * @param array<int, string>                  $map        Mapping from numeric codes to string labels.
     * @param string                              ...$keys    Priority-ordered keys to check.
     *
     * @return string|null Enumerated string value if found, null otherwise.
     */
    public function enumeratedStringValue(array $dictionary, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $dictionary[$key];
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed === '') {
                    continue;
                }

                if (is_numeric($trimmed)) {
                    $code = (int) $trimmed;

                    return $map[$code] ?? $trimmed;
                }

                return $trimmed;
            }

            if (is_int($candidate)) {
                return $map[$candidate] ?? (string) $candidate;
            }

            if (is_float($candidate)) {
                $code = (int) $candidate;

                return $map[$code] ?? (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Extracts boolean flags from dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary containing flag data.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return array<string, bool> Dictionary of flag names to boolean values.
     */
    public function extractFlags(array $dictionary): array
    {
        return $this->flagExtractor->extractFlags($dictionary);
    }
}
