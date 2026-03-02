<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Iptc;

/**
 * IPTC-related TIFF tag identifiers.
 *
 * Tag 33723 (0x83BB) was registered by the Newspaper Association of America (NAA)
 * and is the standard TIFF mechanism for embedding IPTC-IIM metadata.
 *
 * @see https://www.iptc.org/std/IIM/
 */
final readonly class IptcTag
{
    /**
     * IPTC/NAA record embedded in TIFF IFD0.
     *
     * Contains raw IPTC-IIM (Information Interchange Model) binary data.
     * Tag ID: 33723 (0x83BB), Type: LONG or UNDEFINED
     */
    public const int IPTC_NAA = 0x83BB;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
