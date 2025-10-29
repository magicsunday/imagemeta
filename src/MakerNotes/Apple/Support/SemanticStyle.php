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
use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Normalises Apple semantic style payloads into preset, warmth and tone tuples.
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
     * @param array<int|string, mixed> $dictionary
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    public static function fromDictionary(array $dictionary): ?array
    {
        if (!array_key_exists('SemanticStyle', $dictionary)) {
            return null;
        }

        /** @var array<int|string, mixed>|bool|float|int|string|null $value */
        $value = $dictionary['SemanticStyle'];

        return self::fromValue($value);
    }

    /**
     * Normalises the supplied semantic style collection when possible.
     *
     * @param array<int|string, mixed>|bool|float|int|string|null $value
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    public static function fromValue(array|string|int|float|bool|null $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        /** @var array<int|string, mixed> $semantic */
        $semantic = $value;

        $entries = self::normaliseEntries($semantic);
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
     * @param array<int|string, mixed> $semantic
     *
     * @return array<int|string, mixed>|null
     */
    private static function normaliseEntries(array $semantic): ?array
    {
        if (!array_is_list($semantic)) {
            foreach (['values', 'Values'] as $key) {
                if (array_key_exists($key, $semantic) && is_array($semantic[$key])) {
                    /** @var array<int|string, mixed> $values */
                    $values = $semantic[$key];

                    return self::normaliseEntries($values);
                }
            }
        }

        return $semantic;
    }

    /**
     * @param array<int|string, mixed> $entries
     *
     * @return string|int|float|bool|null
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
                if (is_array($value)) {
                    foreach (['value', 'Value'] as $innerKey) {
                        if (array_key_exists($innerKey, $value)) {
                            $inner = $value[$innerKey];
                            if (!is_array($inner)) {
                                $value = $inner;
                            }

                            break;
                        }
                    }

                    if (is_array($value)) {
                        continue;
                    }
                }

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
