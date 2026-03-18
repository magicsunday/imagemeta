<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse;

/**
 * Centralised DoS-prevention limits for all metadata parsers.
 *
 * Every constant represents the maximum number of entries a parser will
 * accept before aborting to prevent resource exhaustion from malicious
 * or malformed files. Protocol-mandated limits (e.g. JPEG segment size)
 * remain in their respective parser classes because they are defined by
 * the file-format specification, not by a safety policy.
 *
 * ISO BMFF item-level structures (iloc, iinf, iref) may legitimately
 * contain thousands of items in multi-image files (bursts, animations),
 * so their limits are set to 10 000. Box types that rarely exceed a
 * handful of entries (dref, stsd) use lower ceilings.
 */
final class ParserLimits
{
    /**
     * Maximum items in an iloc box (ISO 14496-12 §8.11.3).
     */
    public const int MAX_ILOC_ITEMS          = 10_000;

    /**
     * Maximum extents per item in an iloc box (ISO 14496-12 §8.11.3).
     */
    public const int MAX_ILOC_EXTENTS        = 10_000;

    /**
     * Maximum entries in an iinf box (ISO 14496-12 §8.11.6).
     */
    public const int MAX_IINF_ENTRIES        = 10_000;

    /**
     * Maximum references per iref entry (ISO 14496-12 §8.11.12).
     */
    public const int MAX_IREF_REFERENCES     = 10_000;

    /**
     * Maximum reference entry boxes in an iref box (ISO 14496-12 §8.11.12).
     */
    public const int MAX_IREF_ENTRIES        = 10_000;

    /**
     * Maximum data references in a dref box (ISO 14496-12 §8.7.2).
     *
     * Most files contain exactly one self-referencing data reference,
     * so 1 000 is already extremely generous.
     */
    public const int MAX_DREF_ENTRIES        = 1_000;

    /**
     * Maximum sample description entries in an stsd box (ISO 14496-12 §8.5.2).
     */
    public const int MAX_STSD_ENTRIES        = 100;

    /**
     * Maximum key entries in a QuickTime metadata keys box.
     */
    public const int MAX_KEYS_ENTRIES        = 1_000;

    /**
     * Maximum number of chained IFDs in a TIFF/EXIF structure.
     *
     * Standard TIFF files contain at most 2–3 IFDs (IFD0, IFD1, thumbnail).
     * A generous ceiling of 100 prevents runaway traversal.
     */
    public const int MAX_IFD_CHAIN_LENGTH    = 100;

    /**
     * Maximum IFD entries in a TIFF/EXIF directory (TIFF 6.0 §2).
     */
    public const int MAX_IFD_ENTRIES         = 10_000;

    /**
     * Maximum IFD entries in an MPF (Multi-Picture Format) directory.
     */
    public const int MAX_MPF_IFD_ENTRIES     = 512;

    /**
     * Maximum component count for a single MPF IFD entry value.
     */
    public const int MAX_MPF_COMPONENT_COUNT = 1_048_576;

    private function __construct()
    {
    }
}
