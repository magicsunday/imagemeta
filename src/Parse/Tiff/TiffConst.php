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
 * Specification references:
 * - EXIF 2.32, Chapter 4 (Exif image file structure and TIFF field encodings)
 * - EXIF 3.0, Chapter 4 (BigTIFF extensions and 64-bit field types)
 */
final class TiffConst
{
    public const int MAGIC_CLASSIC = 0x002A;
    public const int MAGIC_BIG = 0x002B;

    public const int TYPE_BYTE = 1;
    public const int TYPE_ASCII = 2;
    public const int TYPE_SHORT = 3;
    public const int TYPE_LONG = 4;
    public const int TYPE_RATIONAL = 5;
    public const int TYPE_SBYTE = 6;
    public const int TYPE_UNDEFINED = 7;
    public const int TYPE_SSHORT = 8;
    public const int TYPE_SLONG = 9;
    public const int TYPE_SRATIONAL = 10;
    public const int TYPE_FLOAT = 11;
    public const int TYPE_DOUBLE = 12;
    public const int TYPE_IFD = 13;
    public const int TYPE_LONG8 = 16;
    public const int TYPE_SLONG8 = 17;
    public const int TYPE_IFD8 = 18;

    private function __construct()
    {
    }
}
