<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;

/**
 * Enumerates the resolution units recorded by the XResolution/YResolution tags
 * in EXIF 3.0 §4.6.5.1.11 (image data structure), continuing the EXIF
 * 2.32 §4.6.5.1.11 set.
 */
enum ResolutionUnit: int
{
    use EnumFromIntStringNullable {
        EnumFromIntStringNullable::fromExifValue as private fromExifValueNullable;
    }

    case NONE       = 1;
    case INCHES     = 2;
    case CENTIMETER = 3;

    /**
     * Returns only valid EXIF resolution unit values per EXIF 3.0 §4.6.5.1.11.
     *
     * Values outside the defined set (2 = inches, 3 = centimeters) are
     * considered reserved and rejected.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        $resolved = self::fromExifValueNullable($value);

        return match ($resolved) {
            self::INCHES, self::CENTIMETER => $resolved,
            default                        => null,
        };
    }
}
