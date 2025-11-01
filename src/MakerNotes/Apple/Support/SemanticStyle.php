<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_find;
use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function trim;

/**
 * Normalises Apple semantic style payloads into preset, warmth and tone tuples.
 *
 * @phpstan-type SemanticStyleScalar bool|float|int|string|null
 * @phpstan-type SemanticStyleLayer0 array<int|string, SemanticStyleScalar|object>
 * @phpstan-type SemanticStyleLayer1 array<int|string, SemanticStyleScalar|SemanticStyleLayer0|object>
 * @phpstan-type SemanticStyleLayer2 array<int|string, SemanticStyleScalar|SemanticStyleLayer1|object>
 * @phpstan-type SemanticStyleLayer3 array<int|string, SemanticStyleScalar|SemanticStyleLayer2|object>
 * @phpstan-type SemanticStyleLayer4 array<int|string, SemanticStyleScalar|SemanticStyleLayer3|object>
 * @phpstan-type SemanticStyleLayer5 array<int|string, SemanticStyleScalar|SemanticStyleLayer4|object>
 * @phpstan-type SemanticStyleLayer6 array<int|string, SemanticStyleScalar|SemanticStyleLayer5|object>
 * @phpstan-type SemanticStyleLayer7 array<int|string, SemanticStyleScalar|SemanticStyleLayer6|object>
 * @phpstan-type SemanticStyleLayer8 array<int|string, SemanticStyleScalar|SemanticStyleLayer7|object>
 * @phpstan-type SemanticStyleLayer9 array<int|string, SemanticStyleScalar|SemanticStyleLayer8|object>
 * @phpstan-type SemanticStyleLayer10 array<int|string, SemanticStyleScalar|SemanticStyleLayer9|object>
 * @phpstan-type SemanticStyleDictionary SemanticStyleLayer10
 * @phpstan-type SemanticStyleValue SemanticStyleScalar|SemanticStyleDictionary
 * @phpstan-type SemanticStyleEntries array<int|string, SemanticStyleScalar>
 */
final class SemanticStyle
{
    /**
     * Extracts semantic style values from QuickTime metadata payloads.
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    public static function fromQuickTime(?QuickTimeMeta $quickTime): ?array
    {
        if (!$quickTime instanceof QuickTimeMeta) {
            return null;
        }

        $value = $quickTime->keys['SemanticStyle'] ?? null;

        return self::fromValue($value);
    }

    /**
     * Extracts semantic style values from a dictionary containing a `SemanticStyle` entry.
     *
     * @param SemanticStyleDictionary $dictionary
     *
     * @phpstan-param SemanticStyleDictionary $dictionary
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    public static function fromDictionary(array $dictionary): ?array
    {
        if (!array_key_exists('SemanticStyle', $dictionary)) {
            return null;
        }

        $value = $dictionary['SemanticStyle'];

        if ($value === null) {
            return null;
        }

        if (!is_array($value) && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return null;
        }

        return self::fromValue($value);
    }

    /**
     * Normalises the supplied semantic style collection when possible.
     *
     * @param SemanticStyleValue $value
     *
     * @phpstan-param SemanticStyleValue $value
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    public static function fromValue(array|string|int|float|bool|null $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $entries = self::normaliseEntries($value);
        if ($entries === null) {
            return null;
        }

        $presetRaw     = self::entry($entries, 0);
        $legacyWarmth  = self::entry($entries, 1);
        $modernWarmth  = $legacyWarmth === null ? self::entry($entries, 2) : null;
        $warmthRaw     = $legacyWarmth ?? $modernWarmth;
        $toneRawLegacy = $legacyWarmth !== null ? self::entry($entries, 2) : null;
        $toneRawModern = $legacyWarmth === null ? self::entry($entries, 3, 2) : null;
        $toneRaw       = $toneRawLegacy ?? $toneRawModern;

        $preset = self::preset($presetRaw);
        $warmth = self::float($warmthRaw);
        $tone   = self::float($toneRaw);

        if ($preset === null && $warmth === null && $tone === null) {
            return null;
        }

        return [$preset, $warmth, $tone];
    }

    /**
     * @param SemanticStyleDictionary $semantic
     *
     * @phpstan-param SemanticStyleDictionary $semantic
     *
     * @return SemanticStyleEntries|null
     */
    private static function normaliseEntries(array $semantic): ?array
    {
        if (!array_is_list($semantic)) {
            $nestedKey = array_find(
                ['values', 'Values'],
                static fn (string $key): bool => array_key_exists($key, $semantic) && is_array($semantic[$key])
            );

            if ($nestedKey !== null) {
                $nested = $semantic[$nestedKey];

                if (is_array($nested)) {
                    return self::normaliseEntries($nested);
                }

                return null;
            }
        }

        $result = [];

        foreach ($semantic as $key => $entry) {
            if (is_object($entry)) {
                continue;
            }

            $scalar = self::extractScalar($entry);
            if ($scalar === null && $entry !== null) {
                continue;
            }

            $result[$key] = $scalar;
        }

        return $result === [] ? null : $result;
    }

    /**
     * @param SemanticStyleValue $entry
     *
     * @phpstan-param SemanticStyleValue $entry
     */
    private static function extractScalar(array|bool|float|int|string|null $entry): bool|float|int|string|null
    {
        if (is_array($entry)) {
            $valueKey = array_find(
                ['value', 'Value'],
                static fn (string $innerKey): bool => array_key_exists($innerKey, $entry)
            );

            if ($valueKey !== null) {
                $candidate = $entry[$valueKey];

                if (
                    is_array($candidate)
                    || is_bool($candidate)
                    || is_float($candidate)
                    || is_int($candidate)
                    || is_string($candidate)
                    || $candidate === null
                ) {
                    return self::extractScalar($candidate);
                }

                return null;
            }

            if (array_is_list($entry)) {
                $scalar = null;

                array_find(
                    $entry,
                    static function (array|bool|float|int|string|object|null $candidate) use (&$scalar): bool {
                        if (
                            is_array($candidate)
                            || is_bool($candidate)
                            || is_float($candidate)
                            || is_int($candidate)
                            || is_string($candidate)
                            || $candidate === null
                        ) {
                            $scalar = self::extractScalar($candidate);

                            return $scalar !== null;
                        }

                        return false;
                    }
                );

                return $scalar;
            }

            return null;
        }

        if (is_string($entry) || is_int($entry) || is_float($entry) || is_bool($entry)) {
            return $entry;
        }

        return null;
    }

    /**
     * @param SemanticStyleEntries $entries
     * @param int                  ...$indexes
     *
     * @return SemanticStyleScalar
     */
    private static function entry(array $entries, int ...$indexes): string|int|float|bool|null
    {
        foreach ($indexes as $index) {
            $candidates = [$index, (string) $index, '_' . $index];
            foreach ($candidates as $key) {
                if (!array_key_exists($key, $entries)) {
                    continue;
                }

                $value = $entries[$key];
                if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function preset(string|int|float|bool|null $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private static function float(string|int|float|bool|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value) || is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
