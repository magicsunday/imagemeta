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
 * Names the part of a file whose damage a ParseWarning reports.
 *
 * Container means the structural walk (JPEG markers, ISO BMFF/JXL boxes, RIFF
 * chunks, TIFF directories) stopped or skipped data, so anything stored after
 * the damaged point may be missing. The other scopes mean one embedded metadata
 * payload was unreadable and dropped while the rest of the file was kept.
 */
enum ParseWarningScope: string
{
    case Container = 'container';
    case Exif      = 'exif';
    case Xmp       = 'xmp';
    case Iptc      = 'iptc';
}
