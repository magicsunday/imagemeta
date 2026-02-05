<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Xmp;

use function array_key_exists;
use function is_array;

/**
 * Provides the shared merge semantics for XMP values.
 */
final class XmpValueAccumulator
{
    /**
     * Merges an XMP value into the provided data map while preserving multiplicity.
     *
     * When the same property appears multiple times in different XMP segments,
     * this method keeps all entries in their observed order.
     *
     * @param array<string, string|array<int, string>|XmpLanguageAlternative> $data
     *
     * @param-out array<string, string|array<int, string>|XmpLanguageAlternative> $data
     *
     * @param array<int, string>|string|XmpLanguageAlternative $value
     */
    public static function merge(array &$data, string $key, array|string|XmpLanguageAlternative $value): void
    {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;

            return;
        }

        $existing = $data[$key];

        if ($existing instanceof XmpLanguageAlternative || $value instanceof XmpLanguageAlternative) {
            $data[$key] = self::mergeLanguageAlternatives($existing, $value);

            return;
        }

        // Handle case where existing value is an array
        if (is_array($existing)) {
            if (is_array($value)) {
                // Merge arrays and preserve order/duplicates.
                $data[$key] = [...$existing, ...$value];
            } else {
                // Append the single value as-is, even if it duplicates.
                $data[$key] = [...$existing, $value];
            }

            return;
        }

        // Handle case where existing value is a string
        if (is_array($value)) {
            // Preserve the existing string and append the array values.
            $data[$key] = [$existing, ...$value];

            return;
        }

        // Both are strings: preserve multiplicity by storing both values.
        $data[$key] = [$existing, $value];
    }

    /**
     * @param array<int, string>|string|XmpLanguageAlternative $existing
     * @param array<int, string>|string|XmpLanguageAlternative $value
     */
    private static function mergeLanguageAlternatives(
        array|string|XmpLanguageAlternative $existing,
        array|string|XmpLanguageAlternative $value,
    ): XmpLanguageAlternative {
        $first = $existing instanceof XmpLanguageAlternative
            ? $existing
            : XmpLanguageAlternative::fromValue($existing);
        $second = $value instanceof XmpLanguageAlternative
            ? $value
            : XmpLanguageAlternative::fromValue($value);

        return XmpLanguageAlternative::merge($first, $second);
    }
}
