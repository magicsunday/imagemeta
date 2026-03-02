<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Adobe;

/**
 * Adobe-defined TIFF tag identifiers.
 *
 * Adobe XMP Specification Part 3: Storage in Files defines tag identifiers
 * for embedding XMP metadata within TIFF-based file formats.
 */
final readonly class AdobeTag
{
    /**
     * XMP metadata packet embedded in a TIFF IFD.
     *
     * Adobe XMP Specification Part 3 — tag 700 (0x02BC), type BYTE or UNDEFINED,
     * count = length of XMP packet, value = UTF-8 encoded XMP/RDF XML.
     *
     * EXIF 3.0 §References [4] references CIPA DC-010 for XMP integration.
     */
    public const int XMP_PACKET = 0x02BC;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
