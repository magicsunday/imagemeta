<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

/**
 * Represents the byte ordering identifiers used inside TIFF and EXIF headers.
 */
enum Endian: string
{
    /** Little-endian ordering ("II"). */
    case Little = 'II';
    /** Big-endian ordering ("MM"). */
    case Big = 'MM';

    /**
     * Resolves the endian marker from the TIFF byte-order indicator.
     *
     * @param string $marker Two-byte byte-order marker ("II" or "MM").
     */
    public static function tryFromByteOrder(string $marker): ?self
    {
        return match ($marker) {
            self::Little->value => self::Little,
            self::Big->value    => self::Big,
            default             => null,
        };
    }
}
