<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Tiff;

/**
 * TIFF/IT and TIFF supplement tag identifiers.
 *
 * Tags defined by TIFF/IT (ISO 12639), TIFF supplements, and ICC.1
 * that extend the baseline TIFF 6.0 specification for embedding
 * additional metadata in TIFF IFDs.
 */
final readonly class TiffItTag
{
    /**
     * ICC color profile embedded in a TIFF IFD.
     *
     * TIFF 6.0 §Appendix (TIFF/IT); ICC.1:2022 — tag 34675 (0x8773),
     * type UNDEFINED, count = length of ICC profile data,
     * value = complete ICC profile binary.
     */
    public const int ICC_PROFILE = 0x8773;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
