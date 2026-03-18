<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Text;

use function iconv;
use function is_string;
use function restore_error_handler;
use function set_error_handler;
use function trim;

/**
 * Decodes JIS-marker payloads from EXIF UNDEFINED text fields.
 *
 * EXIF 3.0 §4.6.4 maps the `JIS\0\0\0\0\0` marker to JIS-based encodings
 * (ISO-2022-JP / JIS X 0208). Shift-JIS is intentionally not used as default.
 */
final class JisTextDecoder
{
    /**
     * Decodes a JIS payload into UTF-8.
     *
     * @param string $payload Raw payload bytes after the 8-byte marker prefix.
     *
     * @return string|null UTF-8 text or null when decoding fails.
     */
    public static function decode(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $sources = [
            'ISO-2022-JP',
            'ISO-2022-JP-MS',
        ];

        foreach ($sources as $source) {
            $converted = self::convertToUtf8($source, $payload);

            if ($converted === null) {
                continue;
            }

            $trimmed = trim($converted, "\0 ");

            if ($trimmed === '') {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Converts a payload to UTF-8 while handling iconv failures explicitly.
     */
    private static function convertToUtf8(string $source, string $payload): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $converted = iconv($source, 'UTF-8', $payload);
        } finally {
            restore_error_handler();
        }

        return is_string($converted) ? $converted : null;
    }
}
