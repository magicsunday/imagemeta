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
use function array_unique;
use function array_values;
use function in_array;
use function is_array;

/**
 * Provides the shared merge semantics for XMP values.
 */
final class XmpValueAccumulator
{
    /**
     * Merges an XMP value into the provided data map, avoiding duplicates.
     *
     * When the same property appears multiple times in different XMP segments,
     * this method ensures that duplicate values are not accumulated.
     *
     * @param array<string, string|array<int, string>> $data
     * @param array<int, string>|string                $value
     */
    public static function merge(array &$data, string $key, array|string $value): void
    {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;

            return;
        }

        $existing = $data[$key];

        // Handle case where existing value is an array
        if (is_array($existing)) {
            if (is_array($value)) {
                // Merge arrays and deduplicate
                $merged     = [...$existing, ...$value];
                $data[$key] = array_values(array_unique($merged));
            } elseif (!in_array($value, $existing, true)) {
                // Add single value only if not already present
                $existing[] = $value;
                $data[$key] = $existing;
            }

            return;
        }

        // Handle case where existing value is a string
        if (is_array($value)) {
            // Include existing string only if not already in the array
            $merged = in_array($existing, $value, true) ? $value : [$existing, ...$value];

            $data[$key] = array_values(array_unique($merged));

            return;
        }

        // Both are strings - only convert to array if they're different
        if ($existing !== $value) {
            $data[$key] = [$existing, $value];
        }

        // If they're the same, keep the existing single value
    }
}
