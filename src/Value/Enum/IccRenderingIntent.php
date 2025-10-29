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
 * Enumerates the ICC rendering intents referenced by EXIF 3.0 §4.6.3 (shooting
 * conditions) for embedded profiles, matching the guidance from EXIF 2.32
 * §4.6.3.
 */
enum IccRenderingIntent: int
{
    use EnumFromIntStringNullable;

    case PERCEPTUAL                     = 0;
    case MEDIA_RELATIVE_COLORIMETRIC    = 1;
    case SATURATION                     = 2;
    case ICC_ABSOLUTE_COLORIMETRIC      = 3;

    /**
     * Creates an enum instance from the raw ICC header field.
     */
    public static function fromProfileHeaderValue(?int $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * Returns the human readable rendering intent label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PERCEPTUAL                  => 'Perceptual',
            self::MEDIA_RELATIVE_COLORIMETRIC => 'Media-Relative Colorimetric',
            self::SATURATION                  => 'Saturation',
            self::ICC_ABSOLUTE_COLORIMETRIC   => 'ICC-Absolute Colorimetric',
        };
    }
}
