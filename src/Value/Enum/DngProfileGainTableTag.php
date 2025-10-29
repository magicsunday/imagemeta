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
 * Enumerates the DNG profile gain table related tags covered by
 * EXIF 2.32 §4.6.3 and EXIF 3.0 §4.6.3 (shooting conditions, DNG extensions).
 */
enum DngProfileGainTableTag: int
{
    use EnumFromIntStringNullable;

    case GAIN_TABLE_MAP = 0xC7A4;

    /**
     * Creates an enum from the provided EXIF tag identifier when available.
     */
    public static function fromTagId(?int $tag): ?self
    {
        if ($tag === null) {
            return null;
        }

        return self::tryFrom($tag);
    }

    /**
     * Returns the human readable tag label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GAIN_TABLE_MAP => 'ProfileGainTableMap',
        };
    }
}
