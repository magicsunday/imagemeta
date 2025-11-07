<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Legacy;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;

/**
 * Legacy EXIF tag identifiers retained for backwards compatibility.
 *
 * These tags are either:
 * - Deprecated names from older EXIF versions (aliased to current EXIF 3.0 tags)
 * - Microsoft pre-EXIF 3.0 extensions
 * - Removed from newer specifications but needed for old file compatibility
 *
 * New code should use the current EXIF 3.0 tags from ExifTag instead.
 */
final readonly class LegacyTag
{
    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     *
     * Pre-EXIF 3.0 usage. Modern images use ImageDescription or ImageTitle.
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * This is an alias for PHOTOGRAPHIC_SENSITIVITY (0x8827).
     * EXIF 2.x called this tag "ISOSpeedRatings", renamed in EXIF 3.0.
     *
     * @see ExifTag::PHOTOGRAPHIC_SENSITIVITY
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * Aliased to DATETIME (0x0132). EXIF 3.0 calls this ModifyDate.
     *
     * @see ExifTag::DATETIME
     * @see ExifTag::MODIFY_DATE
     */
    public const int DATETIME = 0x0132;

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to EXIF 3.0.
     *
     * Superseded by ExifTag::PHOTOGRAPHER (0xA437) in EXIF 3.0.
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D;

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to EXIF 3.0.
     *
     * Superseded by ExifTag::IMAGE_EDITOR (0xA438) in EXIF 3.0.
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E;

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     *
     * WARNING: This constant has the same hex value (0xA436) as IMAGE_TITLE in EXIF 3.0!
     * EXIF 3.0 reassigned this tag ID. Use ExifTag::CAMERA_FIRMWARE (0xA439) instead.
     *
     * @deprecated Use ExifTag::CAMERA_FIRMWARE
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436;

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     *
     * WARNING: This constant has the same hex value (0xA439) as CAMERA_FIRMWARE in EXIF 3.0!
     * Use ExifTag::RAW_DEVELOPING_SOFTWARE (0xA43A) for the current tag.
     *
     * @deprecated Use ExifTag::RAW_DEVELOPING_SOFTWARE
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439;

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     *
     * WARNING: This constant has the same hex value (0xA43B) as IMAGE_EDITING_SOFTWARE in EXIF 3.0!
     * The tag was renamed/repurposed between EXIF versions.
     *
     * @deprecated Use ExifTag::IMAGE_EDITING_SOFTWARE
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B;

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     *
     * WARNING: This constant has the same hex value (0xA43C) as METADATA_EDITING_SOFTWARE in EXIF 3.0!
     * The tag was renamed/repurposed between EXIF versions.
     *
     * @deprecated Use ExifTag::METADATA_EDITING_SOFTWARE
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C;

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::CAMERA_FIRMWARE (0xA439) instead.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F;

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::RAW_DEVELOPING_SOFTWARE (0xA43A) instead.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930;

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::IMAGE_EDITING_SOFTWARE (0xA43B) instead.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931;

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::METADATA_EDITING_SOFTWARE (0xA43C) instead.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932;

    /**
     * Byte offset to the embedded preview image data.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     * Found in some camera files. Access via numeric ID if needed.
     */
    public const int PREVIEW_IMAGE_START = 0xC51B;

    /**
     * Length of the embedded preview image data in bytes.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_LENGTH = 0xC51C;

    /**
     * Encoding scheme for the embedded preview image.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_ENCODING = 0xC51D;

    /**
     * MIME type describing the embedded preview image format.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_MIME_TYPE = 0xC51E;

    /**
     * Width of the embedded preview image in pixels.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_WIDTH = 0xC51F;

    /**
     * Height of the embedded preview image in pixels.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_HEIGHT = 0xC520;

    /**
     * Colour space of the embedded preview image.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_COLOR_SPACE = 0xC521;

    /**
     * Bit depth of the embedded preview image.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_BIT_DEPTH = 0xC522;

    /**
     * Compression method used for the preview image.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_COMPRESSION = 0xC525;

    /**
     * Scaling factor applied to derive the preview image.
     *
     * Non-standard tag not in EXIF 3.0, TIFF 6.0, or DNG 1.7 specifications.
     */
    public const int PREVIEW_IMAGE_SCALE = 0xC526;

    /**
     * Camera yaw angle relative to true north (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int CAMERA_YAW_DEGREE = 0x9406;

    /**
     * Camera pitch angle relative to the ground plane (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int CAMERA_PITCH_DEGREE = 0x9407;

    /**
     * Camera roll angle relative to the horizon (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int CAMERA_ROLL_DEGREE = 0x9408;

    /**
     * Gimbal yaw angle reported by the aircraft (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int GIMBAL_YAW_DEGREE = 0x9409;

    /**
     * Gimbal pitch angle reported by the aircraft (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int GIMBAL_PITCH_DEGREE = 0x940A;

    /**
     * Gimbal roll angle reported by the aircraft (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int GIMBAL_ROLL_DEGREE = 0x940B;

    /**
     * Aircraft manufacturer name (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int AIRCRAFT_MAKE = 0x940C;

    /**
     * Aircraft model identifier (drone/aircraft metadata).
     *
     * Vendor extension not in EXIF 3.0 specification. Found in DJI and other drone files.
     */
    public const int AIRCRAFT_MODEL = 0x940D;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}

    // ========================================================================
    // TIFF/EP and TIFF Extensions (not in TIFF 6.0 Appendix A or EXIF 3.0)
    // ========================================================================

    /**
     * TIFF/EP extension tag recording processing software information.
     *
     * Used in TIFF/EP (ISO 12234-2) and supported by some RAW formats.
     * Tag ID: 11 (0x000B)
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    /**
     * Offset pointer to additional linked IFDs.
     *
     * TIFF 6.0 Extensions define SubIFDs for hierarchical image structures
     * (e.g., thumbnails, reduced-resolution images). Not in TIFF 6.0 Appendix A.
     * Tag ID: 330 (0x014A)
     */
    public const int SUB_IFDS = 0x014A;

    /**
     * Embedded ICC color profile binary payload.
     *
     * TIFF 6.0 §20 (ICC Profile Tag) and ICC.1:2001-04 specify tag 0x8773 as the
     * container for ICC color profile data embedded directly within TIFF/EXIF files.
     * Not in TIFF 6.0 Appendix A baseline tags.
     * Tag ID: 34675 (0x8773)
     */
    public const int ICC_PROFILE = 0x8773;

    /**
     * Charge level remaining in the battery.
     *
     * TIFF/EP extension tag. Rarely used in modern EXIF implementations.
     * Tag ID: 33423 (0x828F)
     */
    public const int BATTERY_LEVEL = 0x828F;

    /**
     * Repetition pattern for the colour filter array.
     *
     * TIFF/EP extension for describing CFA patterns in RAW images.
     * Tag ID: 33421 (0x828D)
     */
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D;

    /**
     * Indicator describing interlaced scan type.
     *
     * TIFF/IT extension for interlaced image storage.
     * Tag ID: 34857 (0x8829)
     */
    public const int INTERLACE = 0x8829;

    /**
     * Time zone offsets for recorded timestamps.
     *
     * EXIF private tag, rarely used. Superseded by OffsetTime* tags in EXIF 3.0.
     * Tag ID: 34858 (0x882A)
     */
    public const int TIME_ZONE_OFFSET = 0x882A;

    /**
     * Self-timer delay used for the exposure.
     *
     * EXIF private tag, rarely used in modern implementations.
     * Tag ID: 34859 (0x882B)
     */
    public const int SELF_TIMER_MODE = 0x882B;

    /**
     * Noise measurement parameters.
     *
     * TIFF/EP extension for image quality metrics.
     * Tag ID: 41485 (0xA20D)
     */
    public const int NOISE = 0xA20D;

    /**
     * Sequential number assigned by the camera.
     *
     * TIFF/EP extension tag.
     * Tag ID: 41489 (0xA211)
     */
    public const int IMAGE_NUMBER = 0xA211;

    /**
     * Security classification of the image.
     *
     * TIFF/EP extension for classified or sensitive images.
     * Tag ID: 41490 (0xA212)
     */
    public const int SECURITY_CLASSIFICATION = 0xA212;

    /**
     * Processing steps applied to the image.
     *
     * TIFF/EP extension for documenting image processing history.
     * Tag ID: 41491 (0xA213)
     */
    public const int IMAGE_HISTORY = 0xA213;

    /**
     * Identifier for the TIFF/EP standard version used.
     *
     * TIFF/EP (ISO 12234-2) standard identifier tag.
     * Tag ID: 41494 (0xA216)
     */
    public const int TIFF_EP_STANDARD_ID = 0xA216;
}
