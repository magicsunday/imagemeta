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
 * TIFF 6.0 baseline tag identifiers not part of the EXIF specification.
 *
 * These tags are defined in TIFF 6.0 but are NOT listed in EXIF 3.0 §H.6 Tables 64-67.
 * They are supported for compatibility with TIFF files and TIFF-based extensions.
 *
 * @see docs/TIFF6.pdf
 */
final readonly class TiffTag
{
    /**
     * TIFF 6.0 subfile type bitfield describing the purpose of the image data.
     *
     * TIFF 6.0 §8 defines this as a 32-bit bitfield indicating the image type.
     */
    public const int NEW_SUBFILE_TYPE = 0x00FE;

    /**
     * Legacy TIFF 5.0 subfile type value describing the image purpose.
     *
     * Deprecated in TIFF 6.0 but retained for backwards compatibility.
     */
    public const int SUBFILE_TYPE = 0x00FF;

    /**
     * TIFF/EP extension tag recording processing software information.
     *
     * Used in TIFF/EP (ISO 12234-2) and supported by some RAW formats.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    /**
     * Legacy TIFF 6.0 tag storing the document name.
     *
     * Replaced by ImageDescription in modern usage. Retained for TIFF compatibility.
     */
    public const int DOCUMENT_NAME = 0x010D;

    /**
     * Legacy TIFF 6.0 tag recording the host computer name.
     *
     * Removed from EXIF 3.0 but retained for TIFF 6.0 compatibility.
     */
    public const int HOST_COMPUTER = 0x013C;

    /**
     * TIFF predictor for differencing compression schemes.
     *
     * TIFF 6.0 §14 describes the Predictor tag as a mathematical operator applied
     * before compression to improve LZW compression ratios. Valid values are:
     * 1 = No prediction (default), 2 = Horizontal differencing.
     *
     * Used in conjunction with LZW or other lossless compression schemes to encode
     * the difference between adjacent pixels rather than absolute values.
     */
    public const int PREDICTOR = 0x013D;

    /**
     * Offset pointer to additional linked IFDs.
     *
     * TIFF 6.0 Extensions define SubIFDs for hierarchical image structures
     * (e.g., thumbnails, reduced-resolution images).
     */
    public const int SUB_IFDS = 0x014A;

    /**
     * Width of each image tile in pixels.
     *
     * TIFF 6.0 §15 defines tiled images as an alternative to strip-based storage.
     */
    public const int TILE_WIDTH = 0x0142;

    /**
     * Height of each image tile in pixels.
     *
     * TIFF 6.0 §15 specifies tile dimensions for tiled image organization.
     */
    public const int TILE_LENGTH = 0x0143;

    /**
     * Offsets to tiled image data blocks.
     *
     * TIFF 6.0 §15 defines tile offsets for random access to tiled images.
     */
    public const int TILE_OFFSETS = 0x0144;

    /**
     * Total bytes used by each tile.
     *
     * TIFF 6.0 §15 specifies tile byte counts for proper tile data extraction.
     */
    public const int TILE_BYTE_COUNTS = 0x0145;

    /**
     * Embedded ICC color profile binary payload.
     *
     * TIFF 6.0 §20 (ICC Profile Tag) and ICC.1:2001-04 specify tag 0x8773 as the
     * container for ICC color profile data embedded directly within TIFF/EXIF files.
     * The value is the raw ICC profile binary stream conforming to ICC.1 specification.
     *
     * Enables color-managed workflows by providing device-independent color space
     * transformation instructions alongside the image data.
     */
    public const int ICC_PROFILE = 0x8773;

    /**
     * Charge level remaining in the battery.
     *
     * TIFF/EP extension tag. Rarely used in modern EXIF implementations.
     */
    public const int BATTERY_LEVEL = 0x828F;

    /**
     * Repetition pattern for the colour filter array.
     *
     * TIFF/EP extension for describing CFA patterns in RAW images.
     */
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D;

    /**
     * Indicator describing interlaced scan type.
     *
     * TIFF/IT extension for interlaced image storage.
     */
    public const int INTERLACE = 0x8829;

    /**
     * Time zone offsets for recorded timestamps.
     *
     * EXIF private tag, rarely used. Superseded by OffsetTime* tags in EXIF 3.0.
     */
    public const int TIME_ZONE_OFFSET = 0x882A;

    /**
     * Self-timer delay used for the exposure.
     *
     * EXIF private tag, rarely used in modern implementations.
     */
    public const int SELF_TIMER_MODE = 0x882B;

    /**
     * Noise measurement parameters.
     *
     * TIFF/EP extension for image quality metrics.
     */
    public const int NOISE = 0xA20D;

    /**
     * Sequential number assigned by the camera.
     *
     * TIFF/EP extension tag.
     */
    public const int IMAGE_NUMBER = 0xA211;

    /**
     * Security classification of the image.
     *
     * TIFF/EP extension for classified or sensitive images.
     */
    public const int SECURITY_CLASSIFICATION = 0xA212;

    /**
     * Processing steps applied to the image.
     *
     * TIFF/EP extension for documenting image processing history.
     */
    public const int IMAGE_HISTORY = 0xA213;

    /**
     * Identifier for the TIFF/EP standard version used.
     *
     * TIFF/EP (ISO 12234-2) standard identifier tag.
     */
    public const int TIFF_EP_STANDARD_ID = 0xA216;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
