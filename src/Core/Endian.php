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
    case Big    = 'MM';
}
