<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use RuntimeException;

/**
 * Represents a generic parsing failure triggered by malformed input data.
 *
 * Error-code range conventions (new codes MUST use the designated range):
 *
 *   1001–1099  Core / infrastructure (Stream, MemoryBuffer, UInt64, Unpack)
 *   1100–1199  MakerNotes / Apple plist decoders
 *   1200–1299  ISO BMFF box navigation and item-location parsing
 *   1300–1399  ISO BMFF track-media, sample-entry, key-resolver, payload
 *   1400–1499  ISO BMFF QuickTime metadata/value decoders
 *   1500–1599  DNG structure / geometry / profile / calibration validators
 *   1600–1699  TIFF tag-constraint, image-data, sample, color-ink validators
 *   1700–1799  TIFF JPEG, byte-order, offset validators
 *   1800–1899  EXIF model classes, IPTC, ICC parsers
 *   1900–1999  JPEG parser, MPF, FlashPix, JUMBF, audio, frame validators
 *   2000–2099  Reserved (future expansion)
 *
 * Legacy codes allocated before this convention exist outside these ranges.
 * Each code MUST be globally unique — use `grep -rn` to verify before adding.
 */
class ParseError extends RuntimeException
{
    public const int XMP_ALT_DUPLICATE_LANG = 1121;

    public const int XMP_ALT_MISSING_LANG = 1350;

    public const int XMP_XML_DEPTH_LIMIT_EXCEEDED = 2085;
}
