<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

/**
 * Shared constants describing TIFF headers and data types.
 *
 * EXIF 3.0 §4.5 consolidates the TIFF header layout and field types referenced by
 * embedded EXIF payloads, while TIFF 6.0 §2.2 lists the canonical field type
 * identifiers mirrored below (BYTE through DOUBLE), with TIFF 6.0 §2.1 defining
 * the file structure and byte order.
 */
final class TiffConst
{
    /**
     * Classic TIFF magic number (42 decimal).
     * TIFF 6.0 §2.1; EXIF 3.0 §4.5.1.
     */
    public const int MAGIC_CLASSIC = 0x002A;

    /**
     * BigTIFF magic number (43 decimal).
     * BigTIFF specification; EXIF 3.0 §4.5.1.
     */
    public const int MAGIC_BIG = 0x002B;

    /**
     * Alias for the BigTIFF magic number for compatibility with legacy helpers.
     * BigTIFF specification; EXIF 3.0 §4.5.1.
     */
    public const int MAGIC_BIG_TIFF = self::MAGIC_BIG;

    /**
     * Size in bytes of the classic TIFF file header (byte order + magic + IFD offset).
     * TIFF 6.0 §2.1; EXIF 3.0 §4.5.1.
     */
    public const int HEADER_SIZE_CLASSIC = 8;

    /**
     * Size in bytes of the BigTIFF file header (byte order + magic + offset size + reserved + IFD offset).
     * BigTIFF specification; EXIF 3.0 §4.5.1.
     */
    public const int HEADER_SIZE_BIG = 16;

    /**
     * 8-bit unsigned integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_BYTE = 1;

    /**
     * 8-bit byte containing 7-bit ASCII code; last byte NUL.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_ASCII = 2;

    /**
     * 16-bit unsigned integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SHORT = 3;

    /**
     * 32-bit unsigned integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_LONG = 4;

    /**
     * Two LONGs: numerator and denominator.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_RATIONAL = 5;

    /**
     * 8-bit signed (two's complement) integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SBYTE = 6;

    /**
     * 8-bit byte that may contain anything; interpretation depends on field definition.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_UNDEFINED = 7;

    /**
     * 16-bit signed (two's complement) integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SSHORT = 8;

    /**
     * 32-bit signed (two's complement) integer.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SLONG = 9;

    /**
     * Two SLONGs: signed numerator and denominator.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SRATIONAL = 10;

    /**
     * 4-byte IEEE floating point value.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_FLOAT = 11;

    /**
     * 8-byte IEEE double precision floating point value.
     * TIFF 6.0 §2.2; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_DOUBLE = 12;

    /**
     * 32-bit unsigned offset to IFD (TIFF Technical Note 1).
     * BigTIFF specification; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_IFD = 13;

    /**
     * 64-bit unsigned integer (BigTIFF).
     * BigTIFF specification; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_LONG8 = 16;

    /**
     * 64-bit signed (two's complement) integer (BigTIFF).
     * BigTIFF specification; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_SLONG8 = 17;

    /**
     * 64-bit unsigned offset to IFD (BigTIFF).
     * BigTIFF specification; EXIF 3.0 §4.5.2 Table 3.
     */
    public const int TYPE_IFD8 = 18;

    /**
     * Prevents instantiation of the constants-only utility class.
     */
    private function __construct()
    {
    }
}
