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
 * EXIF UNDEFINED text marker identifiers (EXIF 3.0 §4.6.4).
 */
enum UndefinedTextMarker: string
{
    case Ascii                           = 'ASCII';
    case Unicode                         = 'UNICODE';
    case Jis                             = 'JIS';
    case Undefined                       = 'UNDEFINED';

    public const string MARKER_ASCII     = self::Ascii->value;

    public const string MARKER_UNICODE   = self::Unicode->value;

    public const string MARKER_JIS       = self::Jis->value;

    public const string MARKER_UNDEFINED = self::Undefined->value;

    /**
     * Resolves an 8-byte EXIF marker prefix to its canonical identifier.
     *
     * @param string $prefix Exactly the 8-byte character code area.
     *
     * @return string Canonical marker (`ASCII`, `UNICODE`, `JIS`, `UNDEFINED`) or empty string when unknown.
     */
    public static function canonicalMarkerFromPrefix(string $prefix): string
    {
        $stripped   = trim(str_replace(['\\0', "\0"], '', $prefix));

        if ($stripped === '') {
            return self::Undefined->value;
        }

        if (preg_match('/^([A-Za-z]+)/', $stripped, $matches) !== 1) {
            return '';
        }

        $normalized = strtoupper($matches[1]);

        return match ($normalized) {
            self::Ascii->value   => self::Ascii->value,
            self::Unicode->value => self::Unicode->value,
            self::Jis->value     => self::Jis->value,
            self::Undefined->value, 'UNDEF' => self::Undefined->value,
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
        return match (self::tryFrom($marker)) {
            self::Ascii     => CharacterEncoding::Ascii,
            self::Unicode   => CharacterEncoding::Utf8,
            self::Jis       => CharacterEncoding::Jis,
            self::Undefined => CharacterEncoding::Undefined,
            null            => null,
        };
    }
}
