<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Character encoding identifiers used in EXIF fields.
 *
 * EXIF 3.0 §4.6.4 Table 4 defines the character code headers used in UserComment
 * and GPSProcessingMethod fields. This enum provides type-safe encoding constants
 * for text field interpretation across TIFF/EXIF payloads.
 */
enum CharacterEncoding: string
{
    /**
     * 7-bit ASCII encoding.
     * EXIF 3.0 §4.6.4 Table 4; character code "ASCII\0\0\0".
     */
    case Ascii     = 'ASCII';

    /**
     * UTF-8 variable-width Unicode encoding.
     * Used for modern text fields requiring full Unicode support.
     */
    case Utf8      = 'UTF-8';

    /**
     * UTF-16 little-endian encoding.
     * EXIF 3.0 §4.6.3 Table 2 documents UTF-16LE usage in Microsoft XP tags
     * (XPTitle, XPComment, XPAuthor, XPKeywords, XPSubject).
     */
    case Utf16le   = 'UTF-16LE';

    /**
     * UTF-16 big-endian encoding.
     * Alternative UTF-16 byte order for Unicode text fields.
     */
    case Utf16be   = 'UTF-16BE';

    /**
     * JIS X0208-1990 Japanese character set.
     * EXIF 3.0 §4.6.4 Table 4; character code "JIS\0\0\0\0\0".
     */
    case Jis       = 'JIS';

    /**
     * Undefined/unknown encoding.
     * EXIF 3.0 §4.6.4 Table 4; character code "UNDEFINED".
     * Used when encoding cannot be determined or is vendor-specific.
     */
    case Undefined = 'UNDEFINED';
}
