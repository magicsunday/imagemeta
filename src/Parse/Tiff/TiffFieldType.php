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
 * TIFF field type codes (TIFF 6.0 §2.2 / EXIF 3.0 §4.5.2 Table 3).
 */
enum TiffFieldType: int
{
    case Byte      = 0x01;
    case Ascii     = 0x02;
    case Short     = 0x03;
    case Long      = 0x04;
    case Rational  = 0x05;
    case SByte     = 0x06;
    case Undefined = 0x07;
    case SShort    = 0x08;
    case SLong     = 0x09;
    case SRational = 0x0A;
    case Float     = 0x0B;
    case Double    = 0x0C;
    case Ifd       = 0x0D;
    case Long8     = 0x10;
    case SLong8    = 0x11;
    case Ifd8      = 0x12;

    /**
     * Returns the byte width for one component of this TIFF field type.
     */
    public function bytesPerComponent(): int
    {
        return match ($this) {
            self::Byte, self::Ascii, self::SByte, self::Undefined => 1,
            self::Short, self::SShort => 2,
            self::Long, self::Ifd, self::SLong, self::Float => 4,
            self::Rational, self::SRational, self::Double, self::Long8, self::SLong8, self::Ifd8 => 8,
        };
    }
}
