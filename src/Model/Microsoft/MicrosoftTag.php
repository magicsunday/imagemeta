<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Microsoft;

/**
 * Microsoft-defined TIFF/EXIF tag identifiers.
 *
 * Tags defined by Microsoft for Windows Explorer integration and in-place
 * EXIF editing. The XP_* tags store UCS-2 (UTF-16LE) encoded text; PADDING
 * and OFFSET_SCHEMA support in-place metadata editing without rewriting the file.
 */
final readonly class MicrosoftTag
{
    /**
     * Windows XP rating stored by Windows Explorer (0–5 stars); Microsoft EXIF extension.
     */
    public const int RATING = 0x4746;

    /**
     * Windows XP rating as a percentage (0–100); Microsoft EXIF extension.
     */
    public const int RATING_PERCENT = 0x4749;

    /**
     * Windows XP image title stored as UCS-2 (UTF-16LE) encoded BYTE array.
     */
    public const int XP_TITLE = 0x9C9B;

    /**
     * Windows XP image comment stored as UCS-2 (UTF-16LE) encoded BYTE array.
     */
    public const int XP_COMMENT = 0x9C9C;

    /**
     * Windows XP image author stored as UCS-2 (UTF-16LE) encoded BYTE array.
     */
    public const int XP_AUTHOR = 0x9C9D;

    /**
     * Windows XP semicolon-separated keywords stored as UCS-2 (UTF-16LE) encoded BYTE array.
     */
    public const int XP_KEYWORDS = 0x9C9E;

    /**
     * Windows XP image subject stored as UCS-2 (UTF-16LE) encoded BYTE array.
     */
    public const int XP_SUBJECT = 0x9C9F;

    /**
     * Microsoft padding for in-place EXIF editing; content is filler bytes.
     */
    public const int PADDING = 0xEA1C;

    /**
     * Microsoft offset adjustment when EXIF block has been relocated.
     */
    public const int OFFSET_SCHEMA = 0xEA1D;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
