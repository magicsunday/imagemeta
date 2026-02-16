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
 * TIFF 6.0 Appendix A baseline tag identifiers (excluding tags also in EXIF 3.0).
 *
 * This class contains the 44 TIFF 6.0 tags that are NOT part of the EXIF 3.0 specification.
 * Tags that appear in both TIFF 6.0 AND EXIF 3.0 are kept in ExifTag.php to avoid duplication.
 *
 * Source: TIFF 6.0 Specification Final—June 3, 1992, Appendix A: TIFF Tags Sorted by Number
 *
 * @see docs/TIFF6.pdf
 * @see \MagicSunday\ImageMeta\Exif\Model\ExifTag for shared TIFF/EXIF tags
 */
final readonly class TiffTag
{
    /**
     * TIFF 6.0 subfile type bitfield describing the purpose of the image data.
     *
     * TIFF 6.0 §8 defines this as a 32-bit bitfield indicating the image type.
     * Tag ID: 254 (0x00FE), Type: LONG, Count: 1
     */
    public const int NEW_SUBFILE_TYPE = 0x00FE;

    /**
     * Legacy TIFF 5.0 subfile type value describing the image purpose.
     *
     * Deprecated in TIFF 6.0 but retained for backwards compatibility.
     * Tag ID: 255 (0x00FF), Type: SHORT, Count: 1
     */
    public const int SUBFILE_TYPE = 0x00FF;

    /**
     * Thresholding technique applied to image data.
     *
     * TIFF 6.0 Appendix A defines this tag for bilevel image processing.
     * Tag ID: 263 (0x0107), Type: SHORT, Count: 1
     * Values: 1 = No dithering/halftoning, 2 = Ordered dither, 3 = Randomized dither
     */
    public const int THRESHHOLDING = 0x0107;

    /**
     * Width of a dithering or halftoning matrix cell.
     *
     * Used in conjunction with Threshholding tag for bilevel conversion.
     * Tag ID: 264 (0x0108), Type: SHORT, Count: 1
     */
    public const int CELL_WIDTH = 0x0108;

    /**
     * Height of a dithering or halftoning matrix cell.
     *
     * Used in conjunction with Threshholding tag for bilevel conversion.
     * Tag ID: 265 (0x0109), Type: SHORT, Count: 1
     */
    public const int CELL_LENGTH = 0x0109;

    /**
     * Logical order of bits within a byte.
     *
     * TIFF 6.0 defines fill order for compressed image data.
     * Tag ID: 266 (0x010A), Type: SHORT, Count: 1
     * Values: 1 = MSB to LSB (default), 2 = LSB to MSB
     */
    public const int FILL_ORDER = 0x010A;

    /**
     * Name of the scanned document.
     *
     * TIFF 6.0 baseline tag for document imaging applications.
     * Tag ID: 269 (0x010D), Type: ASCII
     */
    public const int DOCUMENT_NAME = 0x010D;

    /**
     * Minimum component value used in the image.
     *
     * TIFF 6.0 baseline tag for defining data range.
     * Tag ID: 280 (0x0118), Type: SHORT, Count: SamplesPerPixel
     */
    public const int MIN_SAMPLE_VALUE = 0x0118;

    /**
     * Maximum component value used in the image.
     *
     * TIFF 6.0 baseline tag for defining data range.
     * Tag ID: 281 (0x0119), Type: SHORT, Count: SamplesPerPixel
     */
    public const int MAX_SAMPLE_VALUE = 0x0119;

    /**
     * Name of each page in a multi-page document.
     *
     * TIFF 6.0 baseline tag for multi-page TIFF files.
     * Tag ID: 285 (0x011D), Type: ASCII
     */
    public const int PAGE_NAME = 0x011D;

    /**
     * X position of the image on the page.
     *
     * TIFF 6.0 baseline tag for page layout control.
     * Tag ID: 286 (0x011E), Type: RATIONAL
     */
    public const int X_POSITION = 0x011E;

    /**
     * Y position of the image on the page.
     *
     * TIFF 6.0 baseline tag for page layout control.
     * Tag ID: 287 (0x011F), Type: RATIONAL
     */
    public const int Y_POSITION = 0x011F;

    /**
     * Byte offsets of free memory blocks in the file.
     *
     * TIFF 6.0 baseline tag for memory management (rarely used).
     * Tag ID: 288 (0x0120), Type: LONG
     */
    public const int FREE_OFFSETS = 0x0120;

    /**
     * Sizes of free memory blocks in bytes.
     *
     * TIFF 6.0 baseline tag for memory management (rarely used).
     * Tag ID: 289 (0x0121), Type: LONG
     */
    public const int FREE_BYTE_COUNTS = 0x0121;

    /**
     * Precision of gray response curve.
     *
     * TIFF 6.0 defines granularity units for GrayResponseCurve.
     * Tag ID: 290 (0x0122), Type: SHORT, Count: 1
     * Values: 1 = 0.1 units, 2 = 0.001 units, 3 = 0.0001 units, etc.
     */
    public const int GRAY_RESPONSE_UNIT = 0x0122;

    /**
     * Optical density values for each grayscale level.
     *
     * TIFF 6.0 baseline tag for calibrated grayscale imaging.
     * Tag ID: 291 (0x0123), Type: SHORT, Count: 2**BitsPerSample
     */
    public const int GRAY_RESPONSE_CURVE = 0x0123;

    /**
     * Options for Group 3 fax compression.
     *
     * TIFF 6.0 §11 defines T.4 (Group 3) fax encoding parameters.
     * Tag ID: 292 (0x0124), Type: LONG, Count: 1
     * Bit 0: 2D encoding, Bit 1: Uncompressed mode, Bit 2: Fill bits
     */
    public const int T4_OPTIONS = 0x0124;

    /**
     * Options for Group 4 fax compression.
     *
     * TIFF 6.0 §12 defines T.6 (Group 4) fax encoding parameters.
     * Tag ID: 293 (0x0125), Type: LONG, Count: 1
     * Bit 0: Uncompressed mode allowed
     */
    public const int T6_OPTIONS = 0x0125;

    /**
     * Page number and total pages in a multi-page document.
     *
     * TIFF 6.0 baseline tag. Value[0] = page number, Value[1] = total pages.
     * Tag ID: 297 (0x0129), Type: SHORT, Count: 2
     */
    public const int PAGE_NUMBER = 0x0129;

    /**
     * Host computer name where the image was created.
     *
     * TIFF 6.0 baseline tag. Removed from EXIF 3.0.
     * Tag ID: 316 (0x013C), Type: ASCII
     */
    public const int HOST_COMPUTER = 0x013C;

    /**
     * Mathematical predictor for differencing compression schemes.
     *
     * TIFF 6.0 §14 describes the Predictor tag for LZW compression optimization.
     * Tag ID: 317 (0x013D), Type: SHORT, Count: 1
     * Values: 1 = No prediction, 2 = Horizontal differencing
     */
    public const int PREDICTOR = 0x013D;

    /**
     * Color lookup table for palette-color images.
     *
     * TIFF 6.0 §6 defines ColorMap for RGB palette images (PhotometricInterpretation=3).
     * Tag ID: 320 (0x0140), Type: SHORT, Count: 3 * (2**BitsPerSample)
     * Structure: Red[0...n-1], Green[0...n-1], Blue[0...n-1]
     */
    public const int COLOR_MAP = 0x0140;

    /**
     * Range of gray levels for halftone dithering.
     *
     * TIFF 6.0 baseline tag for halftone cell optimization.
     * Tag ID: 321 (0x0141), Type: SHORT, Count: 2
     * Value[0] = highlight gray level, Value[1] = shadow gray level
     */
    public const int HALFTONE_HINTS = 0x0141;

    /**
     * Width of each image tile in pixels.
     *
     * TIFF 6.0 §15 defines tiled images as an alternative to strip-based storage.
     * Tag ID: 322 (0x0142), Type: SHORT or LONG, Count: 1
     */
    public const int TILE_WIDTH = 0x0142;

    /**
     * Height of each image tile in pixels.
     *
     * TIFF 6.0 §15 specifies tile dimensions for tiled image organization.
     * Tag ID: 323 (0x0143), Type: SHORT or LONG, Count: 1
     */
    public const int TILE_LENGTH = 0x0143;

    /**
     * Byte offsets to tiled image data blocks.
     *
     * TIFF 6.0 §15 defines tile offsets for random access to tiled images.
     * Tag ID: 324 (0x0144), Type: LONG, Count: TilesPerImage
     */
    public const int TILE_OFFSETS = 0x0144;

    /**
     * Byte counts for each tile.
     *
     * TIFF 6.0 §15 specifies tile byte counts for proper tile data extraction.
     * Tag ID: 325 (0x0145), Type: SHORT or LONG, Count: TilesPerImage
     */
    public const int TILE_BYTE_COUNTS = 0x0145;

    /**
     * Offsets of child IFDs forming a SubIFD tree.
     *
     * TIFF Supplement 1 / DNG 1.7.1.0 defines SubIFDs for organizing
     * reduced-resolution and alternate representation IFDs below a parent.
     * Tag ID: 330 (0x014A), Type: LONG or IFD8 (BigTIFF), Count: N
     */
    public const int SUB_IFDS = 0x014A;

    /**
     * Set of inks used in separated color image.
     *
     * TIFF 6.0 §16 defines InkSet for CMYK and multi-ink printing applications.
     * Tag ID: 332 (0x014C), Type: SHORT, Count: 1
     * Values: 1 = CMYK, 2 = Not CMYK (see InkNames)
     */
    public const int INK_SET = 0x014C;

    /**
     * Names of each ink used in separated image.
     *
     * TIFF 6.0 §16 defines ink names for custom color separations.
     * Tag ID: 333 (0x014D), Type: ASCII
     * Format: NUL-separated strings, one per ink
     */
    public const int INK_NAMES = 0x014D;

    /**
     * Number of inks in a separated image.
     *
     * TIFF 6.0 §16 specifies the count of color separations.
     * Tag ID: 334 (0x014E), Type: SHORT, Count: 1
     * Usually 4 for CMYK, but can be higher for custom inks
     */
    public const int NUMBER_OF_INKS = 0x014E;

    /**
     * Component values representing 0% and 100% dot coverage.
     *
     * TIFF 6.0 §16 defines dot range for halftone printing.
     * Tag ID: 336 (0x0150), Type: BYTE or SHORT, Count: 2 or 2*NumberOfInks
     */
    public const int DOT_RANGE = 0x0150;

    /**
     * Target printer description.
     *
     * TIFF 6.0 §16 defines printer identification for color separations.
     * Tag ID: 337 (0x0151), Type: ASCII
     */
    public const int TARGET_PRINTER = 0x0151;

    /**
     * Description of extra components beyond RGB/CMYK.
     *
     * TIFF 6.0 §7 defines extra samples (alpha, masks, etc.).
     * Tag ID: 338 (0x0152), Type: BYTE, Count: number of extra components
     * Values: 0 = Unspecified, 1 = Associated alpha, 2 = Unassociated alpha
     */
    public const int EXTRA_SAMPLES = 0x0152;

    /**
     * Data format of image samples.
     *
     * TIFF 6.0 §19 defines sample format for numeric interpretation.
     * Tag ID: 339 (0x0153), Type: SHORT, Count: SamplesPerPixel
     * Values: 1 = Unsigned int, 2 = Signed int, 3 = IEEE float, 4 = Undefined
     */
    public const int SAMPLE_FORMAT = 0x0153;

    /**
     * Minimum sample value (signed or floating-point).
     *
     * TIFF 6.0 §19 extends MinSampleValue for signed/float data.
     * Tag ID: 340 (0x0154), Type: Any, Count: SamplesPerPixel
     */
    public const int S_MIN_SAMPLE_VALUE = 0x0154;

    /**
     * Maximum sample value (signed or floating-point).
     *
     * TIFF 6.0 §19 extends MaxSampleValue for signed/float data.
     * Tag ID: 341 (0x0155), Type: Any, Count: SamplesPerPixel
     */
    public const int S_MAX_SAMPLE_VALUE = 0x0155;

    /**
     * Transfer range for color separation printing.
     *
     * TIFF 6.0 §16 defines black and white levels for each ink.
     * Tag ID: 342 (0x0156), Type: SHORT, Count: 6
     * Structure: BlackMin, WhiteMin, BlackMax, WhiteMax for YCbCr
     */
    public const int TRANSFER_RANGE = 0x0156;

    /**
     * JPEG coding process used.
     *
     * TIFF 6.0 §22 (JPEG compression) defines the JPEG algorithm variant.
     * Tag ID: 512 (0x0200), Type: SHORT, Count: 1
     * Values: 1 = Baseline, 14 = Lossless with Huffman coding
     */
    public const int JPEG_PROC = 0x0200;

    /**
     * Restart interval for JPEG compression.
     *
     * TIFF 6.0 §22 defines restart marker spacing in JPEG data.
     * Tag ID: 515 (0x0203), Type: SHORT, Count: 1
     */
    public const int JPEG_RESTART_INTERVAL = 0x0203;

    /**
     * Lossless predictor selection values for JPEG.
     *
     * TIFF 6.0 §22 defines predictor for lossless JPEG coding.
     * Tag ID: 517 (0x0205), Type: SHORT, Count: SamplesPerPixel
     */
    public const int JPEG_LOSSLESS_PREDICTORS = 0x0205;

    /**
     * Point transform values for lossless JPEG.
     *
     * TIFF 6.0 §22 defines bit shift for lossless JPEG precision scaling.
     * Tag ID: 518 (0x0206), Type: SHORT, Count: SamplesPerPixel
     */
    public const int JPEG_POINT_TRANSFORMS = 0x0206;

    /**
     * Offsets to JPEG quantization tables.
     *
     * TIFF 6.0 §22 points to Q-tables for JPEG decompression.
     * Tag ID: 519 (0x0207), Type: LONG, Count: SamplesPerPixel
     */
    public const int JPEG_Q_TABLES = 0x0207;

    /**
     * Offsets to JPEG DC Huffman tables.
     *
     * TIFF 6.0 §22 points to DC Huffman coding tables.
     * Tag ID: 520 (0x0208), Type: LONG, Count: SamplesPerPixel
     */
    public const int JPEG_DC_TABLES = 0x0208;

    /**
     * Offsets to JPEG AC Huffman tables.
     *
     * TIFF 6.0 §22 points to AC Huffman coding tables.
     * Tag ID: 521 (0x0209), Type: LONG, Count: SamplesPerPixel
     */
    public const int JPEG_AC_TABLES = 0x0209;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
