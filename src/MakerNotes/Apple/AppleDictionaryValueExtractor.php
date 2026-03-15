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
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
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

    private AppleRationalNormalizer $rationalNormalizer;

    public function __construct()
    {
        $this->flagExtractor      = new AppleFlagExtractor();
        $this->rationalNormalizer = new AppleRationalNormalizer();
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

        if (($epoch === null) && ($timescale === null) && ($rawValue === null) && ($flags === null)) {
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            $value = $this->flagExtractor->boolValue($candidate);

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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            $float = $this->rationalNormalizer->normalizeRationalFloat($candidate);

            if ($float !== null) {
                return $float;
            }
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            if (is_int($candidate) || is_float($candidate)) {
                return $this->intOrFloatAsIntOrString($candidate);
            }

            if (is_string($candidate)) {
                $trimmed = trim($candidate);

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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            if (is_int($candidate) || is_float($candidate)) {
                return $this->intOrFloatAsIntOrString($candidate);
            }

            if (is_string($candidate)) {
                $trimmed = trim($candidate);

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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            if (is_float($candidate)) {
                return $candidate;
            }

            if (is_int($candidate) || is_numeric($candidate)) {
                return (float) $candidate;
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            if (is_int($candidate)) {
                return $candidate;
            }

            if (is_numeric($candidate)) {
                return (int) $candidate;
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
            if (is_float($candidate)) {
                return [$candidate];
            }

            if (is_int($candidate)) {
                return [(float) $candidate];
            }

            if (is_string($candidate) && is_numeric($candidate)) {
                return [(float) $candidate];
            }

            if (!is_array($candidate)) {
                continue;
            }

            if (array_key_exists('values', $candidate) && is_array($candidate['values'])) {
                $candidate = $candidate['values'];
            }

            if (!array_is_list($candidate)) {
                continue;
            }

            $result = [];

            foreach ($candidate as $entry) {
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

        if (array_key_exists('values', $value) && is_array($value['values'])) {
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
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
        foreach ($this->valuesForKeys($dictionary, ...$keys) as $candidate) {
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
     * Returns existing dictionary values for the provided key order.
     *
     * @param array<int|string, NativePlistValue> $dictionary
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return list<NativePlistValue>
     *
     * @phpstan-return list<NativePlistValue>
     */
    private function valuesForKeys(array $dictionary, string ...$keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $value */
            $value    = $dictionary[$key];
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Converts integer and float candidates to int|string while preserving integral float handling.
     */
    private function intOrFloatAsIntOrString(int|float $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $intValue = (int) $value;

        if ((float) $intValue === $value) {
            return $intValue;
        }

        return (string) $value;
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
