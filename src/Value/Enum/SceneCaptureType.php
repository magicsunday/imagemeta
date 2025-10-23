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
 * Enumerates known EXIF scene capture type values.
 */
enum SceneCaptureType: int
{
    case STANDARD = 0;
    case LANDSCAPE = 1;
    case PORTRAIT = 2;
    case NIGHT_SCENE = 3;
    case OTHER = 4;

    /**
     * Converts the raw EXIF numeric value into an enum instance when possible.
     *
     * @param int|null $value Raw EXIF scene capture type value.
     */
    public static function fromExifValue(?int $value): ?self
    {
        return $value !== null ? self::tryFrom($value) : null;
    }
}
