<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Binary plist "simple" marker low-nibble subtype values.
 */
enum PlistSimpleMarker: int
{
    case Null    = 0x0;
    case False   = 0x8;
    case True    = 0x9;
    case Url     = 0xC;
    case BaseUrl = 0xD;
    case Uuid    = 0xE;
    case Fill    = 0xF;
}
