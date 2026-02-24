<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Text;

use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;

use function preg_match;
use function str_replace;
use function strtoupper;
use function trim;

/**
 * Central marker/encoding mapping for EXIF UNDEFINED text fields.
 *
 * EXIF 3.0 §4.6.4 defines the 8-byte character code area used by
 * UserComment and GPS UNDEFINED text tags.
 */
final class UndefinedTextMarker
{
    public const string MARKER_ASCII = 'ASCII';

    public const string MARKER_UNICODE = 'UNICODE';

    public const string MARKER_JIS = 'JIS';

    public const string MARKER_UNDEFINED = 'UNDEFINED';

    /**
     * Resolves an 8-byte EXIF marker prefix to its canonical identifier.
     *
     * @param string $prefix Exactly the 8-byte character code area.
     *
     * @return string Canonical marker (`ASCII`, `UNICODE`, `JIS`, `UNDEFINED`) or empty string when unknown.
     */
    public static function canonicalMarkerFromPrefix(string $prefix): string
    {
        $stripped = trim(str_replace(['\\0', "\0"], '', $prefix));

        if ($stripped === '') {
            return self::MARKER_UNDEFINED;
        }

        if (preg_match('/^([A-Za-z]+)/', $stripped, $matches) !== 1) {
            return '';
        }

        $normalized = strtoupper($matches[1]);

        return match ($normalized) {
            self::MARKER_ASCII   => self::MARKER_ASCII,
            self::MARKER_UNICODE => self::MARKER_UNICODE,
            self::MARKER_JIS     => self::MARKER_JIS,
            self::MARKER_UNDEFINED, 'UNDEF' => self::MARKER_UNDEFINED,
            default => '',
        };
    }

    /**
     * Resolves the decoding encoding for a canonical EXIF marker.
     *
     * EXIF 3.0 §4.6.4 maps `UNICODE\0` to UTF-8 semantics.
     *
     * @param string $marker Canonical marker identifier.
     *
     * @return CharacterEncoding|null Encoding enum or null for unknown markers.
     */
    public static function encodingForMarker(string $marker): ?CharacterEncoding
    {
        return match ($marker) {
            self::MARKER_ASCII     => CharacterEncoding::Ascii,
            self::MARKER_UNICODE   => CharacterEncoding::Utf8,
            self::MARKER_JIS       => CharacterEncoding::Jis,
            self::MARKER_UNDEFINED => CharacterEncoding::Undefined,
            default                => null,
        };
    }
}
