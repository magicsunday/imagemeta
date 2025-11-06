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
