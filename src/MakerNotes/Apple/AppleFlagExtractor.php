<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use function array_flip;
use function array_is_list;
use function array_key_exists;
use function array_unique;
use function array_values;
use function ctype_xdigit;
use function hexdec;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sort;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Extracts boolean flags and bitmask positions from Apple maker note dictionaries.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class AppleFlagExtractor
{
    /**
     * Extracts all known Apple boolean flags from dictionary.
     *
     * Checks individual flag keys (FLAG_MAP) and bitmask keys (FLAG_MASK_MAP)
     * to produce a normalized boolean flag dictionary.
     *
     * @param array<int|string, NativePlistValue> $dictionary Dictionary to search.
     *
     * @phpstan-param array<int|string, NativePlistValue> $dictionary
     *
     * @return array<string, bool> Dictionary of flag names to boolean values.
     */
    public function extractFlags(array $dictionary): array
    {
        $flags = [];
        foreach (AppleMaps::FLAG_MAP as $makerKey => $normalized) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $dictionary[$makerKey];
            $bool      = $this->boolValue($candidate);
            if ($bool === null) {
                continue;
            }

            $flags[$normalized] = $bool;
        }

        foreach (AppleMaps::FLAG_MASK_MAP as $makerKey => $bitMap) {
            if (!array_key_exists($makerKey, $dictionary)) {
                continue;
            }

            /** @var NativePlistValue $candidate */
            $candidate     = $dictionary[$makerKey];
            $enabledBits   = $this->bitPositions($candidate);
            $enabledLookup = $enabledBits === null ? null : array_flip($enabledBits);

            foreach ($bitMap as $bitPosition => $normalized) {
                $hasExisting = array_key_exists($normalized, $flags);
                if (!$hasExisting) {
                    $flags[$normalized] = false;
                }

                if ($enabledLookup === null) {
                    continue;
                }

                if (!array_key_exists($bitPosition, $enabledLookup)) {
                    continue;
                }

                if (!$hasExisting) {
                    $flags[$normalized] = true;
                }
            }
        }

        return $flags;
    }

    /**
     * Normalizes Apple bitfield metadata to a list of enabled bit positions.
     *
     * Apple encodes bitfields either as integral masks (decimal/hex strings included) or
     * as ordered collections enumerating the zero-based bit positions that are enabled.
     * Nested collections can appear under helper keys such as "values" or "Flags".
     *
     * @param string|int|float|bool|array<int|string, NativePlistValue>|null $value
     *
     * @return list<int>|null Zero-based bit positions detected in the value or null when the value does not encode a bit mask.
     */
    public function bitPositions(string|int|float|bool|array|null $value): ?array
    {
        if (is_int($value)) {
            return $this->bitPositionsFromMask($value);
        }

        if (is_float($value)) {
            return $this->bitPositionsFromMask((int) $value);
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (str_starts_with($normalized, '0x') || str_starts_with($normalized, '0X')) {
                $hex = substr($normalized, 2);
                if ($hex === '' || !ctype_xdigit($hex)) {
                    return null;
                }

                return $this->bitPositionsFromMask((int) hexdec($hex));
            }

            if (!is_numeric($normalized)) {
                return null;
            }

            return $this->bitPositionsFromMask((int) $normalized);
        }

        if (is_bool($value) || $value === null) {
            return null;
        }

        if ($value === []) {
            return [];
        }

        if (!array_is_list($value)) {
            foreach (['flags', 'Flags', 'value', 'Value', 'mask', 'Mask', 'bitPositions', 'BitPositions'] as $key) {
                if (array_key_exists($key, $value)) {
                    /** @var NativePlistValue $candidate */
                    $candidate = $value[$key];

                    return $this->bitPositions($candidate);
                }
            }

            if (!array_key_exists('values', $value)) {
                return null;
            }

            /** @var NativePlistValue $candidate */
            $candidate = $value['values'];

            return $this->bitPositions($candidate);
        }

        $positions = [];
        $hasEntry  = false;
        foreach ($value as $entry) {
            /** @var NativePlistValue $entry */
            if (is_int($entry) || is_float($entry) || (is_string($entry) && is_numeric($entry))) {
                $position = (int) $entry;
                if ($position >= 0) {
                    $positions[] = $position;
                }

                $hasEntry = true;
                continue;
            }

            $nested = $this->bitPositions($entry);
            if ($nested === null) {
                continue;
            }

            $hasEntry = true;

            foreach ($nested as $bit) {
                $positions[] = $bit;
            }
        }

        if (!$hasEntry) {
            return null;
        }

        if ($positions === []) {
            return [];
        }

        $positions = array_values(array_unique($positions, SORT_NUMERIC));
        sort($positions);

        return $positions;
    }

    /**
     * Converts an integer bit mask into a list of zero-based bit positions.
     *
     * @param int $mask Bit mask with enabled bits set to 1.
     *
     * @return list<int>
     */
    public function bitPositionsFromMask(int $mask): array
    {
        if ($mask <= 0) {
            return [];
        }

        $positions = [];
        $bitIndex  = 0;
        while ($mask !== 0) {
            if (($mask & 1) === 1) {
                $positions[] = $bitIndex;
            }

            $mask >>= 1;
            ++$bitIndex;
        }

        return $positions;
    }

    /**
     * Normalizes a value to boolean representation.
     *
     * @param string|int|float|bool|array<int|string, NativePlistValue>|null $value Raw value to normalize.
     *
     * @phpstan-param string|int|float|bool|null|array<int|string, NativePlistValue> $value
     *
     * @return bool|null Boolean value or null if invalid.
     */
    public function boolValue(string|int|float|bool|array|null $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, ['1', 'true', 'TRUE'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'FALSE'], true)) {
                return false;
            }
        }

        return null;
    }
}
