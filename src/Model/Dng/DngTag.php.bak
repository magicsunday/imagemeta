<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Dng;

/**
 * Centralised list of Adobe DNG (Digital Negative) tag identifiers.
 *
 * Adobe DNG Specification v1.7.1.0 defines tag identifiers for raw image metadata.
 * This class contains DNG-specific tags that extend the TIFF/EXIF specification.
 *
 * @see https://helpx.adobe.com/content/dam/help/en/camera-raw/digital-negative/jcr_content/root/content/flex/items/position/position-par/download_section_733958301/download-1/DNG_Spec_1_7_1_0.pdf
 */
final readonly class DngTag
{
    /**
     * DNG specification version encoded as four bytes.
     * DNG Version 1.0.0.0 (p. 14)
     */
    public const int DNG_VERSION = 0xC612;

    /**
     * DNG backwards compatibility version encoded as four bytes.
     * DNG Version 1.0.0.0 (p. 14)
     */
    public const int DNG_BACKWARD_VERSION = 0xC613;

    /**
     * Unique camera model identifier for DNG raw files.
     * DNG Version 1.0.0.0 (p. 14)
     */
    public const int UNIQUE_CAMERA_MODEL = 0xC614;

    /**
     * Localized camera model name encoded as UTF-8.
     * DNG Version 1.0.0.0 (p. 14)
     */
    public const int LOCALIZED_CAMERA_MODEL = 0xC615;

    /**
     * Color filter array plane color specification.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CFA_PLANE_COLOR = 0xC616;

    /**
     * Color filter array spatial layout pattern.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CFA_LAYOUT = 0xC617;

    /**
     * Linearization lookup table for raw sensor values.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int LINEARIZATION_TABLE = 0xC618;

    /**
     * Dimensions of the repeating black level pattern.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BLACK_LEVEL_REPEAT_DIM = 0xC619;

    /**
     * Black level values for each color plane.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BLACK_LEVEL = 0xC61A;

    /**
     * Horizontal black level delta values.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BLACK_LEVEL_DELTA_H = 0xC61B;

    /**
     * Vertical black level delta values.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BLACK_LEVEL_DELTA_V = 0xC61C;

    /**
     * White level values for each color plane.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int WHITE_LEVEL = 0xC61D;

    /**
     * Default scale factors for the raw image.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int DEFAULT_SCALE = 0xC61E;

    /**
     * Default crop origin coordinates.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int DEFAULT_CROP_ORIGIN = 0xC61F;

    /**
     * Default crop size dimensions.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int DEFAULT_CROP_SIZE = 0xC620;

    /**
     * Primary color matrix transformation from camera RGB to XYZ.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int COLOR_MATRIX_1 = 0xC621;

    /**
     * Secondary color matrix for alternative illuminant.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int COLOR_MATRIX_2 = 0xC622;

    /**
     * Primary camera calibration matrix.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CAMERA_CALIBRATION_1 = 0xC623;

    /**
     * Secondary camera calibration matrix.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CAMERA_CALIBRATION_2 = 0xC624;

    /**
     * Primary dimensionality reduction matrix.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int REDUCTION_MATRIX_1 = 0xC625;

    /**
     * Secondary dimensionality reduction matrix.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int REDUCTION_MATRIX_2 = 0xC626;

    /**
     * Analog balance values per color channel.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int ANALOG_BALANCE = 0xC627;

    /**
     * As-shot neutral white balance coordinates.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int AS_SHOT_NEUTRAL = 0xC628;

    /**
     * As-shot white point chromaticity coordinates.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int AS_SHOT_WHITE_XY = 0xC629;

    /**
     * Baseline exposure offset value.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BASELINE_EXPOSURE = 0xC62A;

    /**
     * Baseline noise level estimate.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BASELINE_NOISE = 0xC62B;

    /**
     * Baseline sharpness estimate.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BASELINE_SHARPNESS = 0xC62C;

    /**
     * Bayer green channel split tolerance.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int BAYER_GREEN_SPLIT = 0xC62D;

    /**
     * Linear response limit for the sensor.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int LINEAR_RESPONSE_LIMIT = 0xC62E;

    /**
     * Camera serial number.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CAMERA_SERIAL_NUMBER = 0xC62F;

    /**
     * Lens specification information for the captured image.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int LENS_INFO = 0xC630;

    /**
     * Chroma blur radius applied during demosaicing.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int CHROMA_BLUR_RADIUS = 0xC631;

    /**
     * Anti-aliasing strength applied during capture.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int ANTI_ALIAS_STRENGTH = 0xC632;

    /**
     * Shadow scale parameter for tone mapping.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int SHADOW_SCALE = 0xC633;

    /**
     * Private DNG data block for vendor extensions.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int DNG_PRIVATE_DATA = 0xC634;

    /**
     * Flag indicating whether maker notes are considered safe to parse.
     * DNG Version 1.0.0.0 (p. 14-19)
     */
    public const int MAKER_NOTE_SAFETY = 0xC635;

    /**
     * Primary calibration illuminant identifier.
     * DNG Version 1.1.0.0 (p. 21)
     */
    public const int CALIBRATION_ILLUMINANT_1 = 0xC65A;

    /**
     * Secondary calibration illuminant identifier.
     * DNG Version 1.1.0.0 (p. 21)
     */
    public const int CALIBRATION_ILLUMINANT_2 = 0xC65B;

    /**
     * Best quality scale factor for rendering.
     * DNG Version 1.1.0.0 (p. 21)
     */
    public const int BEST_QUALITY_SCALE = 0xC65C;

    /**
     * Unique identifier for the raw image data.
     * DNG Version 1.1.0.0 (p. 21)
     */
    public const int RAW_DATA_UNIQUE_ID = 0xC65D;

    /**
     * Original raw file name before conversion.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int ORIGINAL_RAW_FILE_NAME = 0xC68B;

    /**
     * Original raw file data embedded in the DNG.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int ORIGINAL_RAW_FILE_DATA = 0xC68C;

    /**
     * Active image area coordinates.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int ACTIVE_AREA = 0xC68D;

    /**
     * Masked areas within the raw image.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int MASKED_AREAS = 0xC68E;

    /**
     * As-shot ICC profile for color rendering.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int AS_SHOT_ICC_PROFILE = 0xC68F;

    /**
     * As-shot pre-profile matrix for color transforms.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int AS_SHOT_PRE_PROFILE_MATRIX = 0xC690;

    /**
     * Current ICC profile for color rendering.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int CURRENT_ICC_PROFILE = 0xC691;

    /**
     * Current pre-profile matrix for color transforms.
     * DNG Version 1.1.0.0 (p. 22-23)
     */
    public const int CURRENT_PRE_PROFILE_MATRIX = 0xC692;

    /**
     * Colorimetric reference identifier.
     * DNG Version 1.2.0.0 (p. 25)
     */
    public const int COLORIMETRIC_REFERENCE = 0xC6BF;

    /**
     * Camera calibration signature string.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int CAMERA_CALIBRATION_SIGNATURE = 0xC6F3;

    /**
     * Profile calibration signature string.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_CALIBRATION_SIGNATURE = 0xC6F4;

    /**
     * Extra camera profiles embedded in the DNG file.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int EXTRA_CAMERA_PROFILES = 0xC6F5;

    /**
     * As-shot profile name identifier.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int AS_SHOT_PROFILE_NAME = 0xC6F6;

    /**
     * Noise reduction already applied to the raw data.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int NOISE_REDUCTION_APPLIED = 0xC6F7;

    /**
     * Profile name for color rendering.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_NAME = 0xC6F8;

    /**
     * Hue/saturation/value grid dimensions used by the profile maps.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_HUE_SAT_MAP_DIMS = 0xC6F9;

    /**
     * Primary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_1 = 0xC6FA;

    /**
     * Secondary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_2 = 0xC6FB;

    /**
     * Tertiary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_3 = 0xC6FC;

    /**
     * Profile embed policy flag.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_EMBED_POLICY = 0xC6FD;

    /**
     * Profile copyright information.
     * DNG Version 1.2.0.0 (p. 26-28)
     */
    public const int PROFILE_COPYRIGHT = 0xC6FE;

    /**
     * Primary forward transformation matrix.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int FORWARD_MATRIX_1 = 0xC714;

    /**
     * Secondary forward transformation matrix.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int FORWARD_MATRIX_2 = 0xC715;

    /**
     * Preview application name.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_APPLICATION_NAME = 0xC716;

    /**
     * Preview application version.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_APPLICATION_VERSION = 0xC717;

    /**
     * Preview settings name.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_SETTINGS_NAME = 0xC718;

    /**
     * Preview settings digest.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_SETTINGS_DIGEST = 0xC719;

    /**
     * Preview color space identifier.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_COLOR_SPACE = 0xC71A;

    /**
     * Preview date and time.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int PREVIEW_DATE_TIME = 0xC71B;

    /**
     * Raw image digest for integrity verification.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int RAW_IMAGE_DIGEST = 0xC71C;

    /**
     * Original raw file digest for integrity verification.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int ORIGINAL_RAW_FILE_DIGEST = 0xC71D;

    /**
     * Sub-tile block size for tiled images.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int SUB_TILE_BLOCK_SIZE = 0xC71E;

    /**
     * Row interleave factor for image data.
     * DNG Version 1.3.0.0 (p. 30-32)
     */
    public const int ROW_INTERLEAVE_FACTOR = 0xC71F;

    /**
     * Profile look table dimensions.
     * DNG Version 1.3.0.0 (p. 32)
     */
    public const int PROFILE_LOOK_TABLE_DIMS = 0xC725;

    /**
     * Profile look table data.
     * DNG Version 1.3.0.0 (p. 32)
     */
    public const int PROFILE_LOOK_TABLE_DATA = 0xC726;

    /**
     * Opcode list 1 for image processing operations.
     * DNG Version 1.3.0.0 (p. 33)
     */
    public const int OPCODE_LIST_1 = 0xC740;

    /**
     * Opcode list 2 for image processing operations.
     * DNG Version 1.3.0.0 (p. 33)
     */
    public const int OPCODE_LIST_2 = 0xC741;

    /**
     * Opcode list 3 for image processing operations.
     * DNG Version 1.3.0.0 (p. 33)
     */
    public const int OPCODE_LIST_3 = 0xC74E;

    /**
     * Noise profile parameters.
     * DNG Version 1.4.0.0 (p. 35)
     */
    public const int NOISE_PROFILE = 0xC761;

    /**
     * Original default final size dimensions.
     * DNG Version 1.4.0.0 (p. 36)
     */
    public const int ORIGINAL_DEFAULT_FINAL_SIZE = 0xC791;

    /**
     * Original best quality final size dimensions.
     * DNG Version 1.4.0.0 (p. 36)
     */
    public const int ORIGINAL_BEST_QUALITY_FINAL_SIZE = 0xC792;

    /**
     * Original default crop size dimensions.
     * DNG Version 1.4.0.0 (p. 36)
     */
    public const int ORIGINAL_DEFAULT_CROP_SIZE = 0xC793;

    /**
     * Profile hue/saturation map encoding method.
     * DNG Version 1.4.0.0 (p. 37-38)
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODING = 0xC7A3;

    /**
     * Profile look table encoding method.
     * DNG Version 1.4.0.0 (p. 37-38)
     */
    public const int PROFILE_LOOK_TABLE_ENCODING = 0xC7A4;

    /**
     * Baseline exposure offset adjustment.
     * DNG Version 1.4.0.0 (p. 37-38)
     */
    public const int BASELINE_EXPOSURE_OFFSET = 0xC7A5;

    /**
     * Default black render flag.
     * DNG Version 1.4.0.0 (p. 37-38)
     */
    public const int DEFAULT_BLACK_RENDER = 0xC7A6;

    /**
     * New raw image digest for updated integrity verification.
     * DNG Version 1.4.0.0 (p. 38)
     */
    public const int NEW_RAW_IMAGE_DIGEST = 0xC7A7;

    /**
     * Raw to preview gain value.
     * DNG Version 1.4.0.0 (p. 38)
     */
    public const int RAW_TO_PREVIEW_GAIN = 0xC7A8;

    /**
     * Cache blob for performance optimization.
     * DNG Version 1.4.0.0 (p. 38)
     */
    public const int CACHE_BLOB = 0xC7A9;

    /**
     * Cache version identifier.
     * DNG Version 1.4.0.0 (p. 38)
     */
    public const int CACHE_VERSION = 0xC7AA;

    /**
     * Default user crop region.
     * DNG Version 1.4.0.0 (p. 39)
     */
    public const int DEFAULT_USER_CROP = 0xC7B5;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
