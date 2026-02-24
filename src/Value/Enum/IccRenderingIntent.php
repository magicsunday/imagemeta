<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the ICC rendering intents referenced by EXIF 3.0 §4.6.3 (shooting
 * conditions) for embedded profiles.
 */
enum IccRenderingIntent: int
{
    use EnumFromIntStringNullable;

    case Perceptual                = 0;
    case MediaRelativeColorimetric = 1;
    case Saturation                = 2;
    case IccAbsoluteColorimetric   = 3;

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
     * Returns the human-readable rendering intent label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Perceptual                => 'Perceptual',
            self::MediaRelativeColorimetric => 'Media-Relative Colorimetric',
            self::Saturation                => 'Saturation',
            self::IccAbsoluteColorimetric   => 'ICC-Absolute Colorimetric',
        };
    }
}
