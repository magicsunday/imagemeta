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
 * Enumerates TIFF/EXIF photometric interpretations.
 */
enum Photometric: int
{
    case WHITE_IS_ZERO     = 0;
    case BLACK_IS_ZERO     = 1;
    case RGB               = 2;
    case PALETTE_COLOR     = 3;
    case TRANSPARENCY_MASK = 4;
    case CMYK              = 5;
    case YCBCR             = 6;
    case CIELAB            = 8;
    case ICCLAB            = 9;

    /**
     * Converts raw values into the backed enum instance.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = is_string($value) ? (int) $value : $value;

        return self::tryFrom($intValue);
    }
}
