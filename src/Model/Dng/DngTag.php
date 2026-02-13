<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Dng;

use Deprecated;

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
     * DNG Version 1.0.0.0 (p. 24).
     */
    public const int DNG_VERSION = 0xC612;

    /**
     * DNG backwards compatibility version encoded as four bytes.
     * DNG Version 1.0.0.0 (p. 24).
     */
    public const int DNG_BACKWARD_VERSION = 0xC613;

    /**
     * Unique camera model identifier for DNG raw files.
     * DNG Version 1.0.0.0 (p. 24).
     */
    public const int UNIQUE_CAMERA_MODEL = 0xC614;

    /**
     * Localized camera model name encoded as UTF-8.
     * DNG Version 1.0.0.0 (p. 24).
     */
    public const int LOCALIZED_CAMERA_MODEL = 0xC615;

    /**
     * Color filter array plane color specification.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CFA_PLANE_COLOR = 0xC616;

    /**
     * Color filter array spatial layout pattern.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CFA_LAYOUT = 0xC617;

    /**
     * Linearization lookup table for raw sensor values.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int LINEARIZATION_TABLE = 0xC618;

    /**
     * Dimensions of the repeating black level pattern.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BLACK_LEVEL_REPEAT_DIM = 0xC619;

    /**
     * Black level values for each color plane.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BLACK_LEVEL = 0xC61A;

    /**
     * Horizontal black level delta values.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BLACK_LEVEL_DELTA_H = 0xC61B;

    /**
     * Vertical black level delta values.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BLACK_LEVEL_DELTA_V = 0xC61C;

    /**
     * White level values for each color plane.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int WHITE_LEVEL = 0xC61D;

    /**
     * Default scale factors for the raw image.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int DEFAULT_SCALE = 0xC61E;

    /**
     * Default crop origin coordinates.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int DEFAULT_CROP_ORIGIN = 0xC61F;

    /**
     * Default crop size dimensions.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int DEFAULT_CROP_SIZE = 0xC620;

    /**
     * Primary color matrix transformation from camera RGB to XYZ.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int COLOR_MATRIX_1 = 0xC621;

    /**
     * Secondary color matrix for alternative illuminant.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int COLOR_MATRIX_2 = 0xC622;

    /**
     * Primary camera calibration matrix.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CAMERA_CALIBRATION_1 = 0xC623;

    /**
     * Secondary camera calibration matrix.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CAMERA_CALIBRATION_2 = 0xC624;

    /**
     * Primary dimensionality reduction matrix.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int REDUCTION_MATRIX_1 = 0xC625;

    /**
     * Secondary dimensionality reduction matrix.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int REDUCTION_MATRIX_2 = 0xC626;

    /**
     * Analog balance values per color channel.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int ANALOG_BALANCE = 0xC627;

    /**
     * As-shot neutral white balance coordinates.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int AS_SHOT_NEUTRAL = 0xC628;

    /**
     * As-shot white point chromaticity coordinates.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int AS_SHOT_WHITE_XY = 0xC629;

    /**
     * Baseline exposure offset value.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BASELINE_EXPOSURE = 0xC62A;

    /**
     * Baseline noise level estimate.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BASELINE_NOISE = 0xC62B;

    /**
     * Baseline sharpness estimate.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BASELINE_SHARPNESS = 0xC62C;

    /**
     * Bayer green channel split tolerance.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int BAYER_GREEN_SPLIT = 0xC62D;

    /**
     * Linear response limit for the sensor.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int LINEAR_RESPONSE_LIMIT = 0xC62E;

    /**
     * Camera serial number.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CAMERA_SERIAL_NUMBER = 0xC62F;

    /**
     * Lens specification information for the captured image.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int LENS_INFO = 0xC630;

    /**
     * Chroma blur radius applied during demosaicing.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int CHROMA_BLUR_RADIUS = 0xC631;

    /**
     * Anti-aliasing strength applied during capture.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int ANTI_ALIAS_STRENGTH = 0xC632;

    /**
     * Shadow scale parameter for tone mapping.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int SHADOW_SCALE = 0xC633;

    /**
     * Private DNG data block for vendor extensions.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int DNG_PRIVATE_DATA = 0xC634;

    /**
     * Flag indicating whether maker notes are considered safe to parse.
     * DNG Version 1.0.0.0 (p. 24-42).
     */
    public const int MAKER_NOTE_SAFETY = 0xC635;

    /**
     * Primary calibration illuminant identifier.
     * DNG Version 1.1.0.0 (p. 43-44).
     */
    public const int CALIBRATION_ILLUMINANT_1 = 0xC65A;

    /**
     * Secondary calibration illuminant identifier.
     * DNG Version 1.1.0.0 (p. 43-44).
     */
    public const int CALIBRATION_ILLUMINANT_2 = 0xC65B;

    /**
     * Best quality scale factor for rendering.
     * DNG Version 1.1.0.0 (p. 43-44).
     */
    public const int BEST_QUALITY_SCALE = 0xC65C;

    /**
     * Unique identifier for the raw image data.
     * DNG Version 1.1.0.0 (p. 43-44).
     */
    public const int RAW_DATA_UNIQUE_ID = 0xC65D;

    /**
     * Original raw file name before conversion.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int ORIGINAL_RAW_FILE_NAME = 0xC68B;

    /**
     * Original raw file data embedded in the DNG.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int ORIGINAL_RAW_FILE_DATA = 0xC68C;

    /**
     * Active image area coordinates.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int ACTIVE_AREA = 0xC68D;

    /**
     * Masked areas within the raw image.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int MASKED_AREAS = 0xC68E;

    /**
     * As-shot ICC profile for color rendering.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int AS_SHOT_ICC_PROFILE = 0xC68F;

    /**
     * As-shot pre-profile matrix for color transforms.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int AS_SHOT_PRE_PROFILE_MATRIX = 0xC690;

    /**
     * Current ICC profile for color rendering.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int CURRENT_ICC_PROFILE = 0xC691;

    /**
     * Current pre-profile matrix for color transforms.
     * DNG Version 1.1.0.0 (p. 45).
     */
    public const int CURRENT_PRE_PROFILE_MATRIX = 0xC692;

    /**
     * Colorimetric reference identifier.
     * DNG Version 1.2.0.0 (p. 47).
     */
    public const int COLORIMETRIC_REFERENCE = 0xC6BF;

    /**
     * Camera calibration signature string.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int CAMERA_CALIBRATION_SIGNATURE = 0xC6F3;

    /**
     * Profile calibration signature string.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_CALIBRATION_SIGNATURE = 0xC6F4;

    /**
     * Extra camera profiles embedded in the DNG file.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int EXTRA_CAMERA_PROFILES = 0xC6F5;

    /**
     * As-shot profile name identifier.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int AS_SHOT_PROFILE_NAME = 0xC6F6;

    /**
     * Noise reduction already applied to the raw data.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int NOISE_REDUCTION_APPLIED = 0xC6F7;

    /**
     * Profile name for color rendering.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_NAME = 0xC6F8;

    /**
     * Hue/saturation/value grid dimensions used by the profile maps.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_HUE_SAT_MAP_DIMS = 0xC6F9;

    /**
     * Primary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_1 = 0xC6FA;

    /**
     * Secondary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_2 = 0xC6FB;

    /**
     * Profile tone curve for non-linear tone mapping.
     * DNG Version 1.2.0.0 (p. 48-55).
     *
     * Note: this tag was previously misidentified as ProfileHueSatMapData3.
     * The actual ProfileHueSatMapData3 tag ID is 0xCD39 (DNG 1.7.0.0).
     */
    public const int PROFILE_TONE_CURVE = 0xC6FC;

    #[Deprecated(message: <<<'TXT'
    Use PROFILE_TONE_CURVE instead. This alias preserves backward
                 compatibility but maps to ProfileToneCurve (0xC6FC), not
                 ProfileHueSatMapData3 (0xCD39).
    TXT)]
    public const int PROFILE_HUE_SAT_MAP_DATA_3 = self::PROFILE_TONE_CURVE;

    /**
     * Profile embed policy flag.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_EMBED_POLICY = 0xC6FD;

    /**
     * Profile copyright information.
     * DNG Version 1.2.0.0 (p. 48-55).
     */
    public const int PROFILE_COPYRIGHT = 0xC6FE;

    /**
     * Primary forward transformation matrix.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int FORWARD_MATRIX_1 = 0xC714;

    /**
     * Secondary forward transformation matrix.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int FORWARD_MATRIX_2 = 0xC715;

    /**
     * Preview application name.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_APPLICATION_NAME = 0xC716;

    /**
     * Preview application version.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_APPLICATION_VERSION = 0xC717;

    /**
     * Preview settings name.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_SETTINGS_NAME = 0xC718;

    /**
     * Preview settings digest.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_SETTINGS_DIGEST = 0xC719;

    /**
     * Preview color space identifier.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_COLOR_SPACE = 0xC71A;

    /**
     * Preview date and time.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int PREVIEW_DATE_TIME = 0xC71B;

    /**
     * Raw image digest for integrity verification.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int RAW_IMAGE_DIGEST = 0xC71C;

    /**
     * Original raw file digest for integrity verification.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int ORIGINAL_RAW_FILE_DIGEST = 0xC71D;

    /**
     * Sub-tile block size for tiled images.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int SUB_TILE_BLOCK_SIZE = 0xC71E;

    /**
     * Row interleave factor for image data.
     * DNG Version 1.3.0.0 (p. 58-61).
     */
    public const int ROW_INTERLEAVE_FACTOR = 0xC71F;

    /**
     * Profile look table dimensions.
     * DNG Version 1.3.0.0 (p. 61).
     */
    public const int PROFILE_LOOK_TABLE_DIMS = 0xC725;

    /**
     * Profile look table data.
     * DNG Version 1.3.0.0 (p. 61).
     */
    public const int PROFILE_LOOK_TABLE_DATA = 0xC726;

    /**
     * Opcode list 1 for image processing operations.
     * DNG Version 1.3.0.0 (p. 61).
     */
    public const int OPCODE_LIST_1 = 0xC740;

    /**
     * Opcode list 2 for image processing operations.
     * DNG Version 1.3.0.0 (p. 61).
     */
    public const int OPCODE_LIST_2 = 0xC741;

    /**
     * Opcode list 3 for image processing operations.
     * DNG Version 1.3.0.0 (p. 61).
     */
    public const int OPCODE_LIST_3 = 0xC74E;

    /**
     * Noise profile parameters.
     * DNG Version 1.4.0.0 (p. 62).
     */
    public const int NOISE_PROFILE = 0xC761;

    /**
     * Original default final size dimensions.
     * DNG Version 1.4.0.0 (p. 63-64).
     */
    public const int ORIGINAL_DEFAULT_FINAL_SIZE = 0xC791;

    /**
     * Original best quality final size dimensions.
     * DNG Version 1.4.0.0 (p. 63-64).
     */
    public const int ORIGINAL_BEST_QUALITY_FINAL_SIZE = 0xC792;

    /**
     * Original default crop size dimensions.
     * DNG Version 1.4.0.0 (p. 63-64).
     */
    public const int ORIGINAL_DEFAULT_CROP_SIZE = 0xC793;

    /**
     * Profile hue/saturation map encoding method.
     * DNG Version 1.4.0.0 (p. 65-66).
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODING = 0xC7A3;

    /**
     * Profile look table encoding method.
     * DNG Version 1.4.0.0 (p. 65-66).
     */
    public const int PROFILE_LOOK_TABLE_ENCODING = 0xC7A4;

    /**
     * Baseline exposure offset adjustment.
     * DNG Version 1.4.0.0 (p. 65-66).
     */
    public const int BASELINE_EXPOSURE_OFFSET = 0xC7A5;

    /**
     * Default black render flag.
     * DNG Version 1.4.0.0 (p. 65-66).
     */
    public const int DEFAULT_BLACK_RENDER = 0xC7A6;

    /**
     * New raw image digest for updated integrity verification.
     * DNG Version 1.4.0.0 (p. 67).
     */
    public const int NEW_RAW_IMAGE_DIGEST = 0xC7A7;

    /**
     * Raw to preview gain value.
     * DNG Version 1.4.0.0 (p. 67).
     */
    public const int RAW_TO_PREVIEW_GAIN = 0xC7A8;

    /**
     * Cache blob for performance optimization.
     * DNG Version 1.4.0.0 (p. 67).
     */
    public const int CACHE_BLOB = 0xC7A9;

    /**
     * Cache version identifier.
     * DNG Version 1.4.0.0 (p. 67).
     */
    public const int CACHE_VERSION = 0xC7AA;

    /**
     * Default user crop region.
     * DNG Version 1.4.0.0 (p. 67).
     */
    public const int DEFAULT_USER_CROP = 0xC7B5;

    /**
     * Specifies the encoding of any depth data in the file.
     * DNG Version 1.5.0.0 (p. 68).
     */
    public const int DEPTH_FORMAT = 0xC7E9;

    /**
     * Specifies distance from the camera represented by the zero value in the depth map.
     * DNG Version 1.5.0.0 (p. 68).
     */
    public const int DEPTH_NEAR = 0xC7EA;

    /**
     * Specifies distance from the camera represented by the maximum value in the depth map.
     * DNG Version 1.5.0.0 (p. 69).
     */
    public const int DEPTH_FAR = 0xC7EB;

    /**
     * Specifies the measurement units for the DepthNear and DepthFar tags.
     * DNG Version 1.5.0.0 (p. 69).
     */
    public const int DEPTH_UNITS = 0xC7EC;

    /**
     * Specifies the measurement geometry for the depth map.
     * DNG Version 1.5.0.0 (p. 70).
     */
    public const int DEPTH_MEASURE_TYPE = 0xC7ED;

    /**
     * Documents how the enhanced image data was processed.
     * DNG Version 1.5.0.0 (p. 70).
     */
    public const int ENHANCE_PARAMS = 0xC7EE;

    /**
     * Spatially varying gain tables that can be applied while processing the image.
     * DNG Version 1.6.0.0 (p. 71).
     */
    public const int PROFILE_GAIN_TABLE_MAP = 0xCD2D;

    /**
     * Semantic mask name identifier.
     * DNG Version 1.6.0.0 (p. 77).
     */
    public const int SEMANTIC_NAME = 0xCD2E;

    /**
     * Semantic mask instance identifier.
     * DNG Version 1.6.0.0 (p. 78).
     */
    public const int SEMANTIC_INSTANCE_ID = 0xCD30;

    /**
     * Sub-area of a semantic mask.
     * DNG Version 1.6.0.0 (p. 81).
     */
    public const int MASK_SUB_AREA = 0xCD38;

    /**
     * RGB lookup tables for applying to floating point data.
     * DNG Version 1.6.0.0 (p. 83).
     */
    public const int RGB_TABLES = 0xCD3F;

    /**
     * Tertiary calibration illuminant identifier.
     * DNG Version 1.7.0.0 (p. 86).
     */
    public const int CALIBRATION_ILLUMINANT_3 = 0xCD31;

    /**
     * Tertiary camera calibration matrix.
     * DNG Version 1.7.0.0 (p. 88).
     */
    public const int CAMERA_CALIBRATION_3 = 0xCD32;

    /**
     * Tertiary color matrix transformation from camera RGB to XYZ.
     * DNG Version 1.7.0.0 (p. 87).
     */
    public const int COLOR_MATRIX_3 = 0xCD33;

    /**
     * Tertiary forward transformation matrix.
     * DNG Version 1.7.0.0 (p. 90).
     */
    public const int FORWARD_MATRIX_3 = 0xCD34;

    /**
     * Data for primary illuminant.
     * DNG Version 1.7.0.0 (p. 91).
     */
    public const int ILLUMINANT_DATA_1 = 0xCD35;

    /**
     * Data for secondary illuminant.
     * DNG Version 1.7.0.0 (p. 92).
     */
    public const int ILLUMINANT_DATA_2 = 0xCD36;

    /**
     * Data for tertiary illuminant.
     * DNG Version 1.7.0.0 (p. 93).
     */
    public const int ILLUMINANT_DATA_3 = 0xCD37;

    /**
     * Tertiary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     * DNG Version 1.7.0.0 (p. 89).
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_3_V17 = 0xCD39;

    /**
     * Tertiary dimensionality reduction matrix.
     * DNG Version 1.7.0.0 (p. 90).
     */
    public const int REDUCTION_MATRIX_3 = 0xCD3A;

    /**
     * Spatially varying gain tables (second version) for image processing.
     * DNG Version 1.7.0.0 (p. 94).
     */
    public const int PROFILE_GAIN_TABLE_MAP_2 = 0xCD40;

    /**
     * Column interleave factor for raw image data layout.
     * DNG Version 1.7.1.0 (p. 99).
     */
    public const int COLUMN_INTERLEAVE_FACTOR = 0xCD43;

    /**
     * Image sequence information for multi-frame captures.
     * DNG Version 1.7.0.0 (p. 96).
     */
    public const int IMAGE_SEQUENCE_INFO = 0xCD44;

    /**
     * Statistical summary of image data properties.
     * DNG Version 1.7.0.0 (p. 97).
     */
    public const int IMAGE_STATS = 0xCD46;

    /**
     * Dynamic range metadata for the camera profile.
     * DNG Version 1.7.0.0 (p. 98).
     */
    public const int PROFILE_DYNAMIC_RANGE = 0xCD47;

    /**
     * Group name for organizing camera profiles.
     * DNG Version 1.7.0.0 (p. 98).
     */
    public const int PROFILE_GROUP_NAME = 0xCD48;

    /**
     * JPEG XL encoding distance parameter.
     * DNG Version 1.7.1.0 (p. 100).
     */
    public const int JXL_DISTANCE = 0xCD49;

    /**
     * JPEG XL encoding effort parameter.
     * DNG Version 1.7.1.0 (p. 100).
     */
    public const int JXL_EFFORT = 0xCD4A;

    /**
     * JPEG XL decoding speed tier parameter.
     * DNG Version 1.7.1.0 (p. 101).
     */
    public const int JXL_DECODE_SPEED = 0xCD4B;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
