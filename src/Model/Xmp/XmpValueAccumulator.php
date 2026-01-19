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
     * Merges an XMP value into the provided data map.
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

        if (is_array($existing)) {
            if (is_array($value)) {
                $data[$key] = [...$existing, ...$value];
            } else {
                $existing[] = $value;
                $data[$key] = $existing;
            }

            return;
        }

        if (is_array($value)) {
            $data[$key] = [$existing, ...$value];

            return;
        }

        $data[$key] = [$existing, $value];
    }
}
